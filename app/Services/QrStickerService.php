<?php

namespace App\Services;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use InvalidArgumentException;
use RuntimeException;

/**
 * Renders a single claim QR code as a print-ready "sticker" in a chosen format.
 *
 * The QR matrix is drawn by hand (vector rects for PDF/SVG, filled rects for the
 * GD raster) rather than via the library's output modules, so we can wrap it with
 * an optional header (campaign expiration) and footer (claim code) and lay it out
 * at an exact physical sticker size — the driver for thermal label printers such
 * as the Zebra ZP 450 (203 DPI, 1"–2" square stock).
 */
class QrStickerService
{
    /** Supported output formats. */
    public const FORMATS = ['pdf', 'png', 'svg'];

    /** Supported error-correction levels (chillerlan EccLevel names). */
    public const ECC_LEVELS = ['L', 'M', 'Q', 'H'];

    /**
     * Rendering revision. It is folded into the QR export cache key, so **bump this
     * whenever the rendered output changes** (layout, margins, fonts, caption sizing,
     * QR options, etc.). Incrementing it invalidates every previously cached bundle so
     * downloads reflect the new rendering instead of serving stale files until TTL.
     */
    public const RENDER_VERSION = 2;

    /**
     * Minimum sticker size (inches) that can carry BOTH a header and a footer and
     * still leave the QR enough module size to scan reliably. Below this the two
     * caption bands shrink the QR too far (a ~140-char claim payload lands at
     * ~2.7px/module on a 1" sticker — under the safe ~4px threshold), so both
     * captions are only allowed at >= this size.
     */
    public const MIN_SIZE_BOTH_CAPTIONS = 1.5;

    /** Fraction of the caption band height a glyph may occupy (leaves breathing room). */
    private const CAPTION_HEIGHT_RATIO = 0.8;

    /** Bundled scalable font for PNG captions (GD's built-in bitmap fonts can't scale). */
    private function fontPath(): string
    {
        return resource_path('fonts/DejaVuSans.ttf');
    }

    /**
     * PNG (raster) output requires the GD extension. Present on the Vapor/Lambda
     * runtime but not guaranteed on every host, so the UI gates the option on this.
     */
    public static function pngSupported(): bool
    {
        return extension_loaded('gd');
    }

    /**
     * Render one sticker and return the raw file bytes.
     *
     * @param  string  $data  the QR payload (claim URI)
     * @param  string  $format  one of self::FORMATS
     * @param  float  $inches  sticker edge length in inches (square)
     * @param  int  $dpi  dots per inch (drives PNG pixel dimensions)
     * @param  string  $ecc  one of self::ECC_LEVELS
     * @param  ?string  $header  optional top caption (e.g. expiration date)
     * @param  ?string  $footer  optional bottom caption (e.g. claim code)
     */
    public function render(
        string $data,
        string $format,
        float $inches,
        int $dpi,
        string $ecc,
        ?string $header = null,
        ?string $footer = null,
    ): string {
        $format = strtolower($format);
        if (! in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException("Unsupported QR format: {$format}");
        }
        if ($format === 'png' && ! self::pngSupported()) {
            throw new RuntimeException('PNG output requires the GD extension, which is not available.');
        }

        $matrix = $this->matrix($data, $ecc);

        return match ($format) {
            'pdf' => $this->renderPdf($matrix, $inches, $header, $footer),
            'png' => $this->renderPng($matrix, $inches, $dpi, $header, $footer),
            'svg' => $this->renderSvg($matrix, $inches, $dpi, $header, $footer),
        };
    }

    /** File extension for a given format. */
    public function extension(string $format): string
    {
        return strtolower($format);
    }

    private function matrix(string $data, string $ecc): QRMatrix
    {
        $options = new QROptions([
            'eccLevel' => $this->eccConst($ecc),
            // Quiet zone (default size 4) is part of the matrix, giving each
            // sticker the mandatory scannable white border for free.
            'addQuietzone' => true,
        ]);

        return (new QRCode($options))->addByteSegment($data)->getQRMatrix();
    }

    private function eccConst(string $ecc): int
    {
        return match (strtoupper($ecc)) {
            'L' => EccLevel::L,
            'M' => EccLevel::M,
            'Q' => EccLevel::Q,
            'H' => EccLevel::H,
            default => throw new InvalidArgumentException("Unsupported ECC level: {$ecc}"),
        };
    }

    /**
     * Proportional sticker layout in whatever unit $total is expressed in
     * (inches for PDF, pixels for PNG/SVG). Keeps the QR square and centered,
     * reserving text bands only for the captions that are actually present.
     *
     * @return array{margin:float,captionX:float,captionW:float,qrX:float,qrY:float,qrSide:float,headerY:float,headerH:float,footerY:float,footerH:float}
     */
    private function layout(float $total, bool $hasHeader, bool $hasFooter): array
    {
        // Minimal outer margin for the QR — its built-in quiet zone already supplies the
        // mandatory scannable border, so we don't waste sticker area doubling it.
        $margin = $total * 0.01;
        // Caption bands are kept slim so the QR stays as large as possible; the QR is
        // height-limited by the bands, so smaller bands = a bigger code and less side gap.
        $band = $total * 0.11;
        // Captions get their own, wider horizontal inset so text never runs to the sticker
        // edge (protects against minor print misalignment on small stock).
        $captionMargin = $total * 0.10;
        $topBand = $hasHeader ? $band : 0.0;
        $botBand = $hasFooter ? $band : 0.0;

        $availW = $total - 2 * $margin;
        $availH = $total - 2 * $margin - $topBand - $botBand;
        $qrSide = min($availW, $availH);

        return [
            'margin' => $margin,
            'captionX' => $captionMargin,
            'captionW' => $total - 2 * $captionMargin,
            'qrX' => ($total - $qrSide) / 2,
            'qrY' => $margin + $topBand + (($availH - $qrSide) / 2),
            'qrSide' => $qrSide,
            'headerY' => $margin,
            'headerH' => $topBand,
            'footerY' => $total - $margin - $botBand,
            'footerH' => $botBand,
        ];
    }

    private function renderPdf(QRMatrix $matrix, float $inches, ?string $header, ?string $footer): string
    {
        $L = $this->layout($inches, (bool) $header, (bool) $footer);
        $n = $matrix->getSize();
        $module = $L['qrSide'] / $n;

        $pdf = new \FPDF('P', 'in', [$inches, $inches]);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->AddPage();
        $pdf->SetFillColor(0, 0, 0);

        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($matrix->check($x, $y)) {
                    // Tiny overlap avoids hairline seams between modules in some viewers.
                    $pdf->Rect($L['qrX'] + $x * $module, $L['qrY'] + $y * $module, $module + 0.002, $module + 0.002, 'F');
                }
            }
        }

        if ($header) {
            $this->pdfCaption($pdf, $this->latin1($header), $L['captionX'], $L['headerY'], $L['captionW'], $L['headerH']);
        }
        if ($footer) {
            $this->pdfCaption($pdf, $this->latin1($footer), $L['captionX'], $L['footerY'], $L['captionW'], $L['footerH']);
        }

        return $pdf->Output('S');
    }

    /**
     * Draw a caption scaled to fill the band: as large as the width allows, capped
     * by the band height. FPDF measures precisely via GetStringWidth at the current
     * font size, so we set a probe size, measure, then scale to the true fit.
     */
    private function pdfCaption(\FPDF $pdf, string $text, float $x, float $y, float $availW, float $bandH): void
    {
        if ($text === '') {
            return;
        }
        $heightPt = $bandH * self::CAPTION_HEIGHT_RATIO * 72;   // inches -> points
        $pdf->SetFont('Helvetica', '', max(1.0, $heightPt));
        $width = $pdf->GetStringWidth($text);                   // inches, at heightPt
        $pt = $width > $availW ? $heightPt * ($availW / $width) : $heightPt;
        $pdf->SetFont('Helvetica', '', max(4.0, $pt));
        $pdf->SetXY($x, $y);
        $pdf->Cell($availW, $bandH, $text, 0, 0, 'C');
    }

    private function renderPng(QRMatrix $matrix, float $inches, int $dpi, ?string $header, ?string $footer): string
    {
        $px = max(1, (int) round($inches * $dpi));
        $L = $this->layout($px, (bool) $header, (bool) $footer);
        $n = $matrix->getSize();
        $module = $L['qrSide'] / $n;

        $img = imagecreatetruecolor($px, $px);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $px, $px, $white);

        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($matrix->check($x, $y)) {
                    $x1 = (int) floor($L['qrX'] + $x * $module);
                    $y1 = (int) floor($L['qrY'] + $y * $module);
                    $x2 = (int) floor($L['qrX'] + ($x + 1) * $module) - 1;
                    $y2 = (int) floor($L['qrY'] + ($y + 1) * $module) - 1;
                    imagefilledrectangle($img, $x1, $y1, max($x1, $x2), max($y1, $y2), $black);
                }
            }
        }

        if ($header) {
            $this->pngCaption($img, $header, $L['captionX'], $L['headerY'], $L['captionW'], $L['headerH'], $black);
        }
        if ($footer) {
            $this->pngCaption($img, $footer, $L['captionX'], $L['footerY'], $L['captionW'], $L['footerH'], $black);
        }

        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /**
     * Draw a caption into the band, scaled (via a bundled TrueType font) to be as
     * large as the width allows, capped by the band height, and centered both ways.
     * Falls back to GD's built-in bitmap font if the TTF can't be loaded.
     */
    private function pngCaption($img, string $text, float $x, float $bandTop, float $availW, float $bandH, int $color): void
    {
        if ($text === '') {
            return;
        }

        $font = $this->fontPath();
        if (! is_file($font) || ! function_exists('imagettfbbox')) {
            $bitmap = 5;
            $tx = (int) max($x, $x + ($availW - strlen($text) * imagefontwidth($bitmap)) / 2);
            $ty = (int) ($bandTop + ($bandH - imagefontheight($bitmap)) / 2);
            imagestring($img, $bitmap, $tx, $ty, $text, $color);

            return;
        }

        // Measure at a probe size, then scale to whichever of width/height binds first.
        $probe = 100.0;
        [$tw, $th] = $this->ttfMetrics($probe, $font, $text);
        $scale = min($availW / $tw, ($bandH * self::CAPTION_HEIGHT_RATIO) / $th);
        $size = $probe * $scale;

        $bbox = imagettfbbox($size, 0, $font, $text);
        $tw = $bbox[2] - $bbox[0];
        $th = $bbox[1] - $bbox[7];
        // Pen X/Y: bbox offsets place the ink box exactly centered in the band.
        $penX = (int) round($x + ($availW - $tw) / 2 - $bbox[0]);
        $penY = (int) round($bandTop + ($bandH - $th) / 2 - $bbox[7]);
        imagettftext($img, $size, 0, $penX, $penY, $color, $font, $text);
    }

    /** @return array{0:float,1:float} rendered [width, height] of $text at $size. */
    private function ttfMetrics(float $size, string $font, string $text): array
    {
        $bbox = imagettfbbox($size, 0, $font, $text);

        return [max(1.0, $bbox[2] - $bbox[0]), max(1.0, $bbox[1] - $bbox[7])];
    }

    private function renderSvg(QRMatrix $matrix, float $inches, int $dpi, ?string $header, ?string $footer): string
    {
        $px = $inches * $dpi;
        $L = $this->layout($px, (bool) $header, (bool) $footer);
        $n = $matrix->getSize();
        $module = $L['qrSide'] / $n;

        $rects = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($matrix->check($x, $y)) {
                    $rx = round($L['qrX'] + $x * $module, 3);
                    $ry = round($L['qrY'] + $y * $module, 3);
                    $m = round($module, 3);
                    $rects .= '<rect x="'.$rx.'" y="'.$ry.'" width="'.$m.'" height="'.$m.'"/>';
                }
            }
        }

        $texts = '';
        if ($header) {
            $texts .= $this->svgCaption($header, $px / 2, $L['headerY'], $L['headerH'], $L['captionW']);
        }
        if ($footer) {
            $texts .= $this->svgCaption($footer, $px / 2, $L['footerY'], $L['footerH'], $L['captionW']);
        }

        // Physical width/height (in) so the sticker prints at the right size; the
        // viewBox works in the px coordinate space used for layout above.
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<svg xmlns="http://www.w3.org/2000/svg" width="'.$inches.'in" height="'.$inches.'in" '
            .'viewBox="0 0 '.round($px, 2).' '.round($px, 2).'">'
            .'<rect width="100%" height="100%" fill="#fff"/>'
            .'<g fill="#000">'.$rects.'</g>'
            .$texts
            .'</svg>';
    }

    /**
     * A caption <text> scaled to fill the band: font-size capped by band height,
     * and textLength pins the drawn width to the available space (lengthAdjust
     * scales the glyphs) so it fills the width without ever overflowing.
     */
    private function svgCaption(string $text, float $cx, float $bandTop, float $bandH, float $availW): string
    {
        $heightFs = $bandH * self::CAPTION_HEIGHT_RATIO;
        $naturalW = $this->estimateTextWidth($text, $heightFs);
        $fs = $naturalW > $availW ? $heightFs * ($availW / $naturalW) : $heightFs;
        $drawW = min($naturalW * ($fs / $heightFs), $availW);

        return '<text x="'.round($cx, 2).'" y="'.round($bandTop + $bandH / 2, 2).'"'
            .' font-size="'.round($fs, 2).'" textLength="'.round($drawW, 2).'" lengthAdjust="spacingAndGlyphs"'
            .' text-anchor="middle" dominant-baseline="central"'
            .' font-family="Helvetica, Arial, sans-serif" fill="#000">'
            .$this->escapeXml($text).'</text>';
    }

    /** Estimate the rendered width of $text at $fs (precise via TTF when GD is present). */
    private function estimateTextWidth(string $text, float $fs): float
    {
        if (function_exists('imagettfbbox') && is_file($this->fontPath())) {
            return $this->ttfMetrics($fs, $this->fontPath(), $text)[0];
        }

        // Fallback: average sans-serif glyph advance ≈ 0.6em.
        return max(1.0, 0.6 * $fs * mb_strlen($text));
    }

    private function escapeXml(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function latin1(string $s): string
    {
        // FPDF core fonts are latin1-encoded; drop anything that can't map.
        return mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    }
}
