<script setup>
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import LogoSvg from '@/Components/LogoSvg.vue';

// Official Cardano starburst, unaltered from cardano.org/brand-assets. Used once,
// as the "built on Cardano" attribution — not as decoration.
import cardanoStarburst from '@/img/cardano/starburst-white.svg';

// Project marks live in resources/js/img/brands; the source files they were
// exported from are kept outside this repo. The rasters are square avatars
// carrying their own backgrounds, so they render as tiles on the light Paper
// band; WayUp ships a wordmark filled dark for the same reason. Every project
// currently ships a mark; the `mono` glyph fallback is kept for the next one
// that doesn't.
import fetchMark from '@/img/brands/fetch.jpg';
import steelswapMark from '@/img/brands/steelswap.jpg';
import strikeMark from '@/img/brands/strike.jpg';
import bodegaMark from '@/img/brands/bodega.jpg';
import wayupMark from '@/img/brands/wayup.svg';
import hoskyMark from '@/img/brands/hosky.jpg';
import snekMark from '@/img/brands/snek.jpg';
import snekFunMark from '@/img/brands/snek.fun.jpg';
import liqwidMark from '@/img/brands/liqwid.png';
import fluidtokensMark from '@/img/brands/fluidtokens.jpg';
import surfMark from '@/img/brands/surf.jpg';
import indigoMark from '@/img/brands/indigo.jpg';
import finestMark from '@/img/brands/finest.jpg';
import l4vaMark from '@/img/brands/l4va.jpg';
import btcKarmaMark from '@/img/brands/btckarma.png';
import masumiMark from '@/img/brands/masumi.png';
import sokosumiMark from '@/img/brands/sokosumi.jpg';
import surgeMark from '@/img/brands/surge.jpg';
import iagonMark from '@/img/brands/iagon.jpg';
import nuvolaMark from '@/img/brands/nuvola.jpg';
import palmyraMark from '@/img/brands/palmyra.jpg';
import nucastMark from '@/img/brands/nucast.jpg';
import stuffMark from '@/img/brands/stuff.jpg';
import usdmMark from '@/img/brands/usdm.jpg';
import usdaMark from '@/img/brands/usda.jpg';
import usdcMark from '@/img/brands/usdc.png';

// Wallet icons — same square avatars users will recognise in the app stores.
import eternlMark from '@/img/wallets/eternl.jpg';
import laceMark from '@/img/wallets/lace.jpg';
import vesprMark from '@/img/wallets/vespr.jpg';
import beginMark from '@/img/wallets/begin.jpg';

// stakePool: optional pool ticker / hex id from config('cardano.ada.stake_pool').
// When present the staking step offers a one-tap web+cardano://stake deep-link;
// when null it falls back to plain in-wallet instructions (no dead deep-link).
const props = defineProps({
    stakePool: { type: String, default: null },
});

// Fallback only — onMounted replaces this with the real location. app.onbd.io is
// the domain that serves /ada/now; bare onbd.io redirects to the marketing site,
// which 404s on /ada/*, so it must not be the value someone copies.
const pageUrl = ref('https://app.onbd.io/ada/now');

// --- Environment detection (best-effort, client-side) --------------------
// There is no reliable way to ask "is web+cardano registered on this device?".
// So we (a) detect a phone, (b) sniff whether we're already inside a wallet's
// in-app browser, and (c) probe deep-links on tap and fall back on a timeout.
const isMobile = ref(false);
const inWalletBrowser = ref(false);
const copied = ref(false);

const WALLET_UA_HINTS = ['eternl', 'vespr', 'begin', 'lace', 'yoroi', 'typhon', 'nufi', 'gerowallet', 'flint'];

onMounted(() => {
    if (typeof window !== 'undefined') pageUrl.value = window.location.href;
    const ua = (navigator.userAgent || '').toLowerCase();
    isMobile.value = /mobi|android|iphone|ipad|ipod/i.test(ua);
    inWalletBrowser.value = WALLET_UA_HINTS.some((w) => ua.includes(w));
});

// Show the "open in your wallet's browser" hint only when it actually helps:
// on a phone, and not already inside a wallet's in-app browser.
const showWalletHint = ref(true);
const walletHintVisible = computed(() => showWalletHint.value && isMobile.value && !inWalletBrowser.value);

async function copyLink() {
    try {
        await navigator.clipboard.writeText(pageUrl.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2200);
    } catch (e) {
        copied.value = false; // clipboard blocked (insecure context / denied)
    }
}

// --- web+cardano deep links ----------------------------------------------
// A browser can't be asked "is web+cardano registered?", so every attempt is a
// probe: fire the scheme, watch for the page losing focus, and if focus never
// leaves within ~1.4s assume nothing handled it and fall back.
function probeDeepLink(href, onNoHandler) {
    let handled = false;
    const onHidden = () => {
        if (document.hidden) handled = true;
    };
    const onBlur = () => {
        handled = true;
    };
    document.addEventListener('visibilitychange', onHidden);
    window.addEventListener('blur', onBlur, { once: true });

    window.setTimeout(() => {
        document.removeEventListener('visibilitychange', onHidden);
        window.removeEventListener('blur', onBlur);
        if (!handled) onNoHandler();
    }, 1400);

    try {
        window.location.href = href;
    } catch (e) {
        onNoHandler();
    }
}

// CIP-158 `browse` authority (Active): hand a wallet an https URL and it opens
// that page in its embedded browser, so the apps below can connect in one tap.
// Implemented by VESPR and Begin — which is why they lead the wallet list.
// Spec: https://github.com/cardano-foundation/CIPs/tree/master/CIP-0158
const browseDeepLink = computed(() => `web+cardano://browse/v1?uri=${encodeURIComponent(pageUrl.value)}`);
const walletOpenNoHandler = ref(false); // probe timed out — offer copy/paste instead

function openInWallet() {
    walletOpenNoHandler.value = false;
    probeDeepLink(browseDeepLink.value, () => (walletOpenNoHandler.value = true));
}

// CIP-13 stake-pool URI, only offered when a pool is configured.
const stakeDeepLink = computed(() => (props.stakePool ? `web+cardano://stake?${props.stakePool}` : null));
const stakeTried = ref(false); // user attempted the one-tap link
const stakeNoHandler = ref(false); // attempt timed out with the page still visible

function tryStakeDeepLink() {
    if (!stakeDeepLink.value) return;
    stakeTried.value = true;
    stakeNoHandler.value = false;
    probeDeepLink(stakeDeepLink.value, () => (stakeNoHandler.value = true));
}

// --- Content -------------------------------------------------------------
// App URLs confirmed by the marketing owner 2026-07-25 — these are the same
// links printed on the Rare Evo trifold handout, so change both together.
// `mark` is a bundled logo; `mono` is the fallback glyph for projects that
// haven't supplied one yet.
const categories = [
    {
        key: 'swap',
        label: 'Swap tokens',
        blurb: 'Trade one token for another at the best price — an aggregator checks every exchange at once.',
        apps: [
            { name: 'Fetch', tag: 'aggregator', url: 'https://cardano.fetchswap.io/', mark: fetchMark },
            { name: 'SteelSwap', tag: 'aggregator', url: 'https://steelswap.io/', mark: steelswapMark },
        ],
    },
    {
        key: 'lending',
        label: 'Lending',
        blurb: 'Earn interest by lending, or borrow against tokens you already hold.',
        apps: [
            { name: 'Liqwid', tag: 'lending', url: 'https://app.liqwid.finance/', mark: liqwidMark },
            { name: 'FluidTokens', tag: 'lending', url: 'https://app.fluidtokens.com/', mark: fluidtokensMark },
            { name: 'Surf', tag: 'lending', url: 'https://surflending.org/app', mark: surfMark },
        ],
    },
    {
        key: 'stables',
        label: 'Stablecoins',
        blurb: 'Park value in dollars on-chain — each coin aims to stay worth $1.',
        apps: [
            { name: 'USDM', tag: 'Moneta', url: 'https://moneta.global/', mark: usdmMark },
            { name: 'USDA', tag: 'Anzens', url: 'https://www.anzens.com/', mark: usdaMark },
            { name: 'USDCx', tag: 'bridge', url: 'https://usdcx.iog.io/bridge', mark: usdcMark },
        ],
    },
    {
        key: 'perps',
        label: 'Perpetuals',
        blurb: 'Go long or short with leverage — non-custodial, so your keys stay yours.',
        apps: [{ name: 'Strike', tag: 'leverage', url: 'https://app.strikefinance.org/', mark: strikeMark }],
    },
    {
        key: 'autotrade',
        label: 'Automated trading',
        blurb: 'Pick a strategy and let it trade for you, without handing over your coins.',
        apps: [{ name: 'Surge', tag: 'strategies', url: 'https://surgecardano.com/', mark: surgeMark }],
    },
    {
        key: 'synths',
        label: 'Synthetics',
        blurb: 'Track the price of gold, oil, or other assets on-chain without ever holding the real thing.',
        apps: [{ name: 'Indigo', tag: 'synthetics', url: 'https://app.indigoprotocol.io/', mark: indigoMark }],
    },
    {
        key: 'rwa',
        label: 'Real-world assets',
        blurb: 'Property, funds, and businesses brought on-chain so anyone can own a slice.',
        apps: [
            { name: 'Finest', tag: 'investments', url: 'https://app.finest.investments/', mark: finestMark },
            { name: 'L4VA', tag: 'vaults', url: 'https://app.l4va.org/', mark: l4vaMark },
            { name: 'Palmyra', tag: 'ecosystem', url: 'https://www.palmyraecosystem.com/', mark: palmyraMark },
        ],
    },
    {
        key: 'ip',
        label: 'IP & media',
        blurb: 'Own a share of a film, a track, or a brand — and the income it earns.',
        apps: [
            { name: 'Nucast', tag: 'film & TV', url: 'https://nucast.io', mark: nucastMark },
            { name: 'Stuff', tag: 'IP rights', url: 'https://stuff.io', mark: stuffMark },
        ],
    },
    {
        key: 'btc',
        label: 'Bitcoin DeFi',
        blurb: 'Put Bitcoin to work through Cardano — earn on BTC without selling it.',
        apps: [{ name: 'BTC Karma', tag: 'BTC staking', url: 'https://staking.btckarma.io/', mark: btcKarmaMark }],
    },
    {
        key: 'agents',
        label: 'AI agents',
        blurb: 'Hire an AI agent to do a job, and let it pay its own way on-chain.',
        apps: [
            { name: 'Masumi', tag: 'agent payments', url: 'https://www.masumi.network/', mark: masumiMark },
            { name: 'Sokosumi', tag: 'hire agents', url: 'https://www.sokosumi.com/', mark: sokosumiMark },
        ],
    },
    {
        key: 'storage',
        label: 'Storage & compute',
        blurb: 'Use a network of ordinary machines instead of a big cloud provider — or rent out your own.',
        apps: [
            { name: 'Iagon', tag: 'DePIN', url: 'https://iagon.com/', mark: iagonMark },
            { name: 'Nuvola', tag: 'DePIN', url: 'https://vola.network/', mark: nuvolaMark },
        ],
    },
    {
        key: 'predict',
        label: 'Prediction markets',
        blurb: 'Put a small stake on real-world outcomes — sports, politics, and more.',
        apps: [{ name: 'Bodega', tag: 'markets', url: 'https://v3.bodegamarket.io/', mark: bodegaMark }],
    },
    {
        key: 'nfts',
        label: 'NFTs',
        blurb: 'Mint, buy, and trade Cardano collectibles and art.',
        apps: [{ name: 'WayUp', tag: 'marketplace', url: 'https://www.wayup.io/', mark: wayupMark }],
    },
    {
        key: 'memes',
        label: 'Memecoins',
        blurb: 'The fun, high-risk corner of Cardano. Treat it as play money — never rent money.',
        apps: [
            { name: 'HOSKY', tag: 'the dog', url: 'https://hosky.io/', mark: hoskyMark },
            { name: 'SNEK', tag: 'the snake', url: 'https://www.snek.com/', mark: snekMark },
            { name: 'snek.fun', tag: 'launchpad', url: 'https://www.snek.fun/', mark: snekFunMark },
        ],
    },
];

// Keeps the "N apps · M things to try" line from drifting out of sync with the data.
const appCount = computed(() => categories.reduce((n, c) => n + c.apps.length, 0));

// Order is deliberate (mobile-first first) — keep it in sync with the trifold's
// wallet cards and the WALLET_UA_HINTS list above.
const wallets = [
    { name: 'Vespr', url: 'https://vespr.xyz', mark: vesprMark },
    { name: 'Begin', url: 'https://begin.is', mark: beginMark },
    { name: 'Lace', url: 'https://lace.io', mark: laceMark },
    { name: 'Eternl', url: 'https://eternl.io', mark: eternlMark },
];

const year = new Date().getFullYear();
</script>

<template>
    <Head title="You've got ADA — now what?">
        <meta name="description" content="You claimed some ADA. Here are three things you can do with it right now — stake it, use your vote, and open the apps that are live on Cardano today." />
        <link rel="preconnect" href="https://fonts.bunny.net" />
        <!-- Varela = Onboard.Ninja's brand face, used for headlines. Inter carries body,
             labels and buttons — and, unlike Varela/Chivo, it actually contains ₳ (U+20B3),
             so the ADA sign renders in the real typeface instead of a system fallback.
             space-grotesk 700 is loaded only for the Discover Cardano partner logotype. -->
        <link href="https://fonts.bunny.net/css?family=varela:400|inter:300,400,500,600,700|space-grotesk:700&display=swap" rel="stylesheet" />
    </Head>

    <div class="ada">
        <!-- Wallet-browser hint: phones, when not already inside a wallet -->
        <transition name="fade">
            <!-- CIP-158 lets us hand this page straight to the wallet's own browser,
                 so the primary action is one tap. Copy/paste is only revealed if the
                 probe finds no handler — no point offering both up front. -->
            <div v-if="walletHintVisible" class="hint" role="note">
                <template v-if="!walletOpenNoHandler">
                    <p class="hint__text">
                        These apps connect in one tap from <strong>inside your wallet's browser</strong>.
                    </p>
                    <button class="hint__go" type="button" @click="openInWallet">Open in wallet</button>
                </template>
                <template v-else>
                    <p class="hint__text">
                        No wallet caught that. <strong>Copy this link</strong> and paste it into Vespr, Begin, or Lace.
                    </p>
                    <button class="hint__go" type="button" @click="copyLink">
                        {{ copied ? 'Copied' : 'Copy link' }}
                    </button>
                </template>
                <button class="hint__close" type="button" aria-label="Dismiss this tip" @click="showWalletHint = false">×</button>
            </div>
        </transition>

        <!-- Deliberately event-free: this page is evergreen, so no conference badge. -->
        <header class="chrome">
            <div class="chrome__in">
                <a class="chrome__brand" href="https://onbd.io" target="_blank" rel="noopener" aria-label="Onboard.Ninja home">
                    <LogoSvg :width="132" :height="21" />
                </a>
            </div>
        </header>

        <!-- ── Hero ─────────────────────────────────────────────── -->
        <section class="band band--blue hero">
            <div class="band__in band__in--narrow">
                <p class="hero__eyebrow">Your claim went through</p>
                <!-- The question is the thesis, so it carries the weight; "You've got ₳DA"
                     is the setup. aria-label so screen readers get "ADA", not the ₳ sign. -->
                <h1 class="hero__title" aria-label="You've got ADA. Now what?">
                    <span class="hero__setup" aria-hidden="true">You've got <span class="hero__ada">₳DA</span>.</span>
                    <span class="hero__ask" aria-hidden="true">Now what<span class="hero__q">?</span></span>
                </h1>
                <p class="hero__sub">
                    Three things you can do with it right now. Your coins stay in your wallet the whole
                    time — start with whichever one appeals.
                </p>
                <nav class="jump" aria-label="Skip to a section">
                    <a class="jump__to" href="#stake">Stake it</a>
                    <a class="jump__to" href="#vote">Use your vote</a>
                    <a class="jump__to" href="#apps">Open the apps</a>
                </nav>
            </div>
        </section>

        <!-- ── Stake ────────────────────────────────────────────── -->
        <section id="stake" class="band band--ink move">
            <div class="band__in band__in--narrow">
                <p class="move__verb">Earn</p>
                <h2 class="move__title">Stake your ADA</h2>
                <!-- Rate checked against on-chain data 2026-07-25 (epoch 645): median
                     annualised delegator ROS across the 14 largest pools over the prior
                     6 epochs was 2.12%/yr; network-wide gross was 2.18%/yr. To re-verify:
                     Koios /pool_history -> epoch_ros, or /epoch_info -> total_rewards ÷
                     active_stake × 73. It drifts down slowly as the reserve depletes, so
                     re-check before reprinting anything. Do not restore the old "3–4%". -->
                <p class="move__lede">
                    Delegate to a stake pool and your ADA starts earning — <strong>around 2% a
                    year</strong>, depending on the pool you pick. It never leaves your wallet, never
                    locks up, and you can change or stop whenever you like.
                </p>

                <div class="actions">
                    <template v-if="stakeDeepLink">
                        <button class="btn btn--go" type="button" @click="tryStakeDeepLink">
                            Delegate to {{ stakePool }}
                        </button>
                        <a class="btn btn--quiet" href="https://cexplorer.io/pool" target="_blank" rel="noopener">Browse pools</a>
                        <a class="btn btn--quiet" href="https://cardano.org/stake-pool-delegation/" target="_blank" rel="noopener">How staking works</a>
                    </template>
                    <template v-else>
                        <a class="btn btn--go" href="https://cardano.org/stake-pool-delegation/" target="_blank" rel="noopener">How to stake</a>
                        <a class="btn btn--quiet" href="https://cexplorer.io/pool" target="_blank" rel="noopener">Browse pools</a>
                    </template>
                </div>

                <transition name="fade">
                    <p v-if="stakeTried && stakeNoHandler" class="fallback">
                        No wallet caught that link. Open the <strong>Staking</strong> tab in your wallet,
                        search for a pool, and tap <strong>Delegate</strong> — or reopen this page from
                        inside your wallet's browser and try again.
                    </p>
                </transition>

                <p class="micro"><span class="micro__m" aria-hidden="true">₳</span> Your first delegation takes a one-time <strong>2 ₳</strong> deposit. You get it back if you ever un-stake.</p>
            </div>
        </section>

        <!-- ── Vote ─────────────────────────────────────────────── -->
        <section id="vote" class="band band--blue move">
            <div class="band__in band__in--narrow">
                <p class="move__verb">Decide</p>
                <h2 class="move__title">Use your vote</h2>
                <p class="move__lede">
                    Your ADA is also voting power over Cardano's treasury and upgrades. Hand it to a
                    <strong>DRep</strong> who votes on your behalf, or vote the proposals yourself.
                    Change your mind as often as you like.
                </p>

                <div class="actions">
                    <a class="btn btn--go" href="https://gov.tools/drep_directory" target="_blank" rel="noopener">Browse DReps on gov.tools</a>
                    <a class="btn btn--quiet" href="https://app.cgov.io/drep" target="_blank" rel="noopener">Or on CGOV</a>
                </div>

                <!-- Two independent directories on purpose: gov.tools has had outages,
                     so CGOV is a live fallback rather than a nice-to-have. -->
                <p class="micro"><span class="micro__m" aria-hidden="true">₳</span> Both sites list the same DReps. If one won't load, use the other.</p>
                <p class="micro"><span class="micro__m" aria-hidden="true">₳</span> Not ready to pick a person? Choose <strong>Abstain</strong>. You stay flexible and lose nothing.</p>
            </div>
        </section>

        <!-- ── Apps · the one light band ─────────────────────────── -->
        <section id="apps" class="band band--paper move">
            <div class="band__in">
                <p class="move__verb">Explore</p>
                <h2 class="move__title">Open the apps</h2>
                <p class="move__lede move__lede--wide">
                    Real products, running on Cardano today. Connect your wallet and try one.
                </p>
                <p class="tally">
                    <!-- "+" because this is a hand-picked starting set, not a census of
                         Cardano — the Discover Cardano link below carries the long tail. -->
                    <strong>{{ appCount }}+</strong> apps
                    <span class="tally__sep" aria-hidden="true">·</span>
                    <strong>{{ categories.length }}</strong> things to try
                    <span class="tally__sep" aria-hidden="true">·</span>
                    all live now
                </p>

                <div class="cats">
                    <article v-for="cat in categories" :key="cat.key" class="cat">
                        <h3 class="cat__label">{{ cat.label }}</h3>
                        <p class="cat__blurb">{{ cat.blurb }}</p>
                        <div class="cat__apps">
                            <a
                                v-for="app in cat.apps"
                                :key="app.name"
                                class="app"
                                :href="app.url"
                                target="_blank"
                                rel="noopener"
                            >
                                <span class="app__tile">
                                    <img
                                        v-if="app.mark"
                                        class="app__mark"
                                        :src="app.mark"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                    />
                                    <span v-else class="app__mono" aria-hidden="true">{{ app.mono }}</span>
                                </span>
                                <span class="app__id">
                                    <span class="app__name">{{ app.name }}</span>
                                    <span class="app__tag">{{ app.tag }}</span>
                                </span>
                                <svg class="app__out" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 17L17 7M17 7H9M17 7v8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            </a>
                        </div>
                    </article>
                </div>

                <!-- Partner block. Discover Cardano's logotype is a CSS wordmark whose
                     "Discover" is near-white, so it sits on their own dark surface
                     (hsl(264 8% 11%)) rather than our Paper band. Gradient and typeface
                     are theirs verbatim. -->
                <a class="partner" href="https://discovercardano.io/explorer" target="_blank" rel="noopener">
                    <span class="partner__id">
                        <span class="partner__k">In partnership with</span>
                        <span class="partner__mark">Discover <span class="partner__g">Cardano</span></span>
                    </span>
                    <span class="partner__go">
                        Explore even more Cardano projects
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 5l7 7-7 7M20 12H4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    </span>
                </a>

                <p class="safe">
                    <strong>Only connect your wallet to sites you trust, and never share your recovery
                    phrase.</strong> No real app will ever ask you for it.
                </p>
            </div>
        </section>

        <!-- ── Close: wallets, host CTA, attribution ─────────────── -->
        <section class="band band--ink close">
            <div class="band__in band__in--narrow">
                <div class="getwallet">
                    <h2 class="close__h">No wallet yet?</h2>
                    <!-- The links here ARE the trusted path, so the warning is about
                         everywhere else — not "type it by hand", which contradicts them. -->
                    <p class="close__p">
                        All four links go to the official sites. Be careful anywhere else — fake wallets
                        spread through search ads and QR codes you weren't expecting.
                    </p>
                    <div class="getwallet__list">
                        <a v-for="w in wallets" :key="w.name" class="wchip" :href="w.url" target="_blank" rel="noopener">
                            <img class="wchip__mark" :src="w.mark" alt="" loading="lazy" decoding="async" />
                            {{ w.name }}
                        </a>
                    </div>
                </div>

                <div class="host">
                    <p class="host__verb">For creators &amp; events</p>
                    <h2 class="close__h">Hosting something? Run your own drop.</h2>
                    <p class="close__p">
                        Onboard.Ninja is how this ADA reached your wallet. Hand out real Cardano tokens or
                        NFTs at your event — no seed phrases, no friction.
                    </p>
                    <div class="actions">
                        <a class="btn btn--go" href="https://onbd.io" target="_blank" rel="noopener">Host your own at onbd.io</a>
                    </div>
                </div>

                <footer class="foot">
                    <a class="foot__built" href="https://cardano.org" target="_blank" rel="noopener">
                        <img class="foot__star" :src="cardanoStarburst" alt="" width="34" height="31" />
                        <span>Powered by Cardano</span>
                    </a>
                    <LogoSvg class="foot__logo" :width="150" :height="24" />
                    <p class="foot__legal">
                        © {{ year }} Onboard.Ninja · A friendly map, not financial advice.
                    </p>
                </footer>
            </div>
        </section>
    </div>
</template>

<style scoped>
/* ══ Tokens ══════════════════════════════════════════════════════════
   Colour is Cardano's official brand palette (cardano.org/brand-assets):
   Blue #0033AD, Black #000000, White #FFFFFF, Paper #F8F8F5, Red #FF5553,
   Green #3B7982.

   Type is ours, not Cardano's kit: Varela (the Onboard.Ninja brand face) for
   headlines, Inter for body, labels and UI. Varela ships a single weight (400),
   so display type gets its presence from size and tracking rather than weight.
   Inter is also the only face here that contains ₳ (U+20B3) — Varela and Chivo
   both lack it — so anything with an ADA sign must resolve to Inter.

   Onboard.Ninja orange lives only inside the wordmark, and the wordmark only
   ever sits on a black band, so it never lands beside Cardano Red.
   ═══════════════════════════════════════════════════════════════════ */
.ada {
    --blue: #0033ad;
    --black: #000000;
    --white: #ffffff;
    --paper: #f8f8f5;
    --red: #ff5553;
    --green: #3b7982;

    /* per-band derived tints */
    --on-blue-dim: #b4c3e8;
    --on-ink-dim: #9a9a9a;
    --on-paper-dim: #55554f;
    --hair-dark: rgba(255, 255, 255, 0.18);
    --hair-light: rgba(0, 0, 0, 0.12);

    --f: 'Inter', system-ui, -apple-system, sans-serif;
    --f-display: 'Varela', 'Inter', system-ui, sans-serif;
    /* Small tracked-uppercase labels. Inter rather than a mono family: one less
       font to load, and it keeps ₳ coverage everywhere. */
    --f-label: 'Inter', system-ui, sans-serif;
    --r: 4px;

    font-family: var(--f);
    color: var(--white);
    background: var(--black);
    -webkit-font-smoothing: antialiased;
    overflow-x: hidden;
}

.ada a {
    color: inherit;
    text-decoration: none;
}
.ada a:focus-visible,
.ada button:focus-visible {
    outline: 2px solid var(--red);
    outline-offset: 3px;
}

/* ══ Band system ════════════════════════════════════════════════════
   The page is the Cardano palette in sequence: black chrome, blue hero,
   black, blue, paper, black. The single Paper band is load-bearing — the
   app logos are a mix of light and dark square avatars and only sit
   correctly on a light neutral.
   ═══════════════════════════════════════════════════════════════════ */
.band {
    padding: clamp(3.25rem, 10vw, 6rem) 0;
}
.band__in {
    width: min(100% - 2.5rem, 62rem);
    margin: 0 auto;
}
/* Caps the measure on the children rather than the container, so a narrow band's
   left edge still lines up with the full-width apps band as you scroll. */
.band__in--narrow > * {
    max-width: 36rem;
}
.band--blue {
    background: var(--blue);
    color: var(--white);
}
.band--ink {
    background: var(--black);
    color: var(--white);
}
.band--paper {
    background: var(--paper);
    color: var(--black);
}

/* ══ Sticky wallet hint ═════════════════════════════════════════════ */
.hint {
    position: sticky;
    top: 0;
    z-index: 30;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.7rem 1.25rem;
    background: var(--black);
    border-bottom: 1px solid var(--hair-dark);
}
.hint__text {
    margin: 0;
    font-size: 0.82rem;
    line-height: 1.4;
    color: var(--on-ink-dim);
}
.hint__text strong {
    color: var(--white);
    font-weight: 700;
}
.hint__go {
    flex: none;
    padding: 0.45rem 0.8rem;
    border: 0;
    border-radius: var(--r);
    background: var(--red);
    color: var(--white);
    font: 700 0.76rem var(--f-label);
    letter-spacing: 0.04em;
    text-transform: uppercase;
    cursor: pointer;
    white-space: nowrap;
}
.hint__go:hover {
    background: var(--white);
    color: var(--blue);
}
.hint__close {
    flex: none;
    border: 0;
    background: transparent;
    color: var(--on-ink-dim);
    font-size: 1.4rem;
    line-height: 1;
    cursor: pointer;
}
.hint__close:hover {
    color: var(--white);
}

/* ══ Chrome ═════════════════════════════════════════════════════════ */
.chrome {
    background: var(--black);
    padding: 1.15rem 0;
}
.chrome__in {
    width: min(100% - 2.5rem, 62rem);
    margin: 0 auto;
    display: flex;
    align-items: center;
}
/* Onboard.Ninja's sanctioned dark-background lockup: orange letters, white mark. */
.chrome__brand {
    display: inline-flex;
    color: var(--white);
}

/* ══ Hero ═══════════════════════════════════════════════════════════
   Type-led, no ornament. ₳ — the ADA currency sign — is the page's
   signature glyph: oversized here, and the marker on every aside below.
   ═══════════════════════════════════════════════════════════════════ */
.hero {
    padding-top: clamp(2.5rem, 8vw, 4.5rem);
}
.hero__eyebrow {
    margin: 0 0 1.15rem;
    font: 700 0.72rem var(--f-label);
    letter-spacing: 0.22em;
    text-transform: uppercase;
    color: var(--on-blue-dim);
}
.hero__title {
    margin: 0;
}
/* Direct children only — the ₳ span inside must stay inline. */
.hero__title > span {
    display: block;
}
.hero__setup {
    font-weight: 300;
    font-size: clamp(1.35rem, 5.5vw, 1.85rem);
    letter-spacing: -0.02em;
    color: var(--on-blue-dim);
}
.hero__ada {
    font-weight: 700;
    color: var(--white);
}
/* Varela ships one weight (400), so display type is set at 400 with tight
   tracking — never 700, which would only trigger faux-bold. */
.hero__ask {
    margin-top: 0.1em;
    font-family: var(--f-display);
    font-weight: 400;
    font-size: clamp(3.5rem, 17.5vw, 6.2rem);
    line-height: 0.92;
    letter-spacing: -0.035em;
}
.hero__q {
    color: var(--red);
}
.hero__sub {
    max-width: 27rem;
    margin: 1.9rem 0 0;
    font-size: 1.06rem;
    line-height: 1.55;
    color: var(--on-blue-dim);
}
.jump {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 2.1rem;
}
.jump__to {
    padding: 0.6rem 0.95rem;
    border: 1px solid var(--hair-dark);
    border-radius: var(--r);
    font: 700 0.86rem var(--f);
    transition: background 0.15s ease, border-color 0.15s ease;
}
.jump__to:hover {
    background: var(--white);
    border-color: var(--white);
    color: var(--blue);
}

/* ══ Moves ══════════════════════════════════════════════════════════
   No 01/02/03 — the three moves aren't a sequence, so they're keyed by
   their verb instead of a false ordinal.
   ═══════════════════════════════════════════════════════════════════ */
.move__verb {
    margin: 0 0 0.9rem;
    padding-bottom: 0.7rem;
    border-bottom: 1px solid var(--hair-dark);
    font: 700 0.72rem var(--f-label);
    letter-spacing: 0.22em;
    text-transform: uppercase;
    color: var(--red);
}
.band--paper .move__verb {
    border-bottom-color: var(--hair-light);
}
.move__title {
    margin: 0;
    font-family: var(--f-display);
    font-weight: 400;
    font-size: clamp(2.05rem, 7.5vw, 2.95rem);
    line-height: 1.06;
    letter-spacing: -0.025em;
}
.move__lede {
    max-width: 32rem;
    margin: 1.05rem 0 0;
    font-size: 1.03rem;
    line-height: 1.6;
}
.move__lede--wide {
    max-width: 34rem;
}
.band--blue .move__lede {
    color: var(--on-blue-dim);
}
.band--ink .move__lede {
    color: var(--on-ink-dim);
}
.band--paper .move__lede {
    color: var(--on-paper-dim);
}
.move__lede strong {
    font-weight: 700;
    color: currentColor;
}
.band--blue .move__lede strong,
.band--ink .move__lede strong {
    color: var(--white);
}

/* ══ Buttons ════════════════════════════════════════════════════════ */
.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
    margin-top: 1.7rem;
}
.btn {
    display: inline-flex;
    align-items: center;
    padding: 0.85rem 1.3rem;
    border: 1px solid transparent;
    border-radius: var(--r);
    font: 700 0.95rem var(--f);
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}
.btn--go {
    background: var(--red);
    color: var(--white);
}
.btn--go:hover {
    background: var(--white);
    color: var(--blue);
}
.btn--quiet {
    background: transparent;
    border-color: var(--hair-dark);
    color: var(--white);
}
.btn--quiet:hover {
    border-color: var(--white);
}

.fallback {
    margin: 1.05rem 0 0;
    padding: 0.9rem 1.05rem;
    border-left: 3px solid var(--red);
    background: rgba(255, 255, 255, 0.06);
    font-size: 0.9rem;
    line-height: 1.55;
    color: var(--on-ink-dim);
}
.fallback strong {
    color: var(--white);
    font-weight: 700;
}

/* ₳ as the aside marker, in place of a bullet or an icon. Absolutely positioned
   rather than a flex child, so inline <strong> inside the copy doesn't get
   promoted to a second column. */
.micro {
    position: relative;
    margin: 1.6rem 0 0;
    padding-left: 1.3rem;
    font-size: 0.86rem;
    line-height: 1.55;
}
.micro__m {
    position: absolute;
    left: 0;
    top: 0;
    font-weight: 700;
    color: var(--red);
}
.band--blue .micro {
    color: var(--on-blue-dim);
}
.band--ink .micro {
    color: var(--on-ink-dim);
}
.micro strong {
    font-weight: 700;
}
.band--blue .micro strong,
.band--ink .micro strong {
    color: var(--white);
}

/* ══ Apps directory (Paper band) ════════════════════════════════════
   Ruled rows rather than cards — a directory, which is what it is.
   ═══════════════════════════════════════════════════════════════════ */
.tally {
    margin: 1.4rem 0 0;
    font: 400 0.8rem var(--f-label);
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--on-paper-dim);
}
.tally strong {
    font-weight: 700;
    color: var(--red);
}
.tally__sep {
    margin: 0 0.3rem;
    opacity: 0.5;
}

.cats {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0;
    margin-top: 2.2rem;
}
.cat {
    padding: 1.5rem 0;
    border-top: 1px solid var(--hair-light);
}
.cat__label {
    margin: 0;
    font-weight: 700;
    font-size: 1.18rem;
    letter-spacing: -0.02em;
}
.cat__blurb {
    max-width: 26rem;
    margin: 0.35rem 0 0;
    font-size: 0.92rem;
    line-height: 1.55;
    color: var(--on-paper-dim);
}
.cat__apps {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 1rem;
}

.app {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.45rem 0.75rem 0.45rem 0.5rem;
    border: 1px solid var(--hair-light);
    border-radius: var(--r);
    background: var(--white);
    transition: border-color 0.15s ease, transform 0.12s ease;
}
.app:hover {
    border-color: var(--red);
    transform: translateY(-2px);
}
.app__tile {
    flex: none;
    display: grid;
    place-items: center;
    width: 30px;
    height: 30px;
    border-radius: 6px;
    overflow: hidden;
    background: var(--paper);
}
.app__mark {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.app__mono {
    font-weight: 700;
    font-size: 1.05rem;
    color: var(--red);
}
.app__id {
    display: flex;
    flex-direction: column;
    line-height: 1.15;
}
.app__name {
    font-weight: 700;
    font-size: 0.92rem;
}
.app__tag {
    font: 400 0.7rem var(--f-label);
    letter-spacing: 0.02em;
    color: var(--on-paper-dim);
}
.app__out {
    width: 14px;
    height: 14px;
    color: var(--on-paper-dim);
}
.app:hover .app__out {
    color: var(--red);
}

/* ── Discover Cardano partner block ─────────────────────────────────
   Their surface, typeface, and gradient — used as given so the logotype
   stays theirs rather than being restyled into ours. */
.partner {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem 1.25rem;
    margin-top: 2.4rem;
    padding: 1.3rem 1.4rem;
    border-radius: var(--r);
    background: #1c1a1e;
    color: #fafafa;
    transition: transform 0.12s ease;
}
.partner:hover {
    transform: translateY(-2px);
}
.partner__k {
    display: block;
    font: 700 0.66rem var(--f-label);
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: rgba(250, 250, 250, 0.55);
    margin-bottom: 0.4rem;
}
.partner__mark {
    display: block;
    font-family: 'Space Grotesk', var(--f);
    font-weight: 700;
    font-size: 1.35rem;
    letter-spacing: -0.025em;
    white-space: nowrap;
    /* Set here, not on .partner — `.ada a { color: inherit }` outranks a single
       class on the anchor and would drag this back to the Paper band's ink. */
    color: #fafafa;
}
.partner__g {
    background-image: linear-gradient(to right, #8c32cc, #e633cc);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}
.partner__go {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    font-weight: 700;
    font-size: 0.9rem;
    color: #fafafa;
}
.partner__go svg {
    width: 16px;
    height: 16px;
    color: #e633cc;
}

.safe {
    margin: 2.4rem 0 0;
    padding: 1.15rem 1.25rem;
    border-left: 3px solid var(--green);
    background: rgba(59, 121, 130, 0.09);
    font-size: 0.94rem;
    line-height: 1.6;
    color: var(--on-paper-dim);
}
.safe strong {
    display: block;
    font-weight: 700;
    color: var(--black);
}

/* ══ Close ══════════════════════════════════════════════════════════ */
.close__h {
    margin: 0;
    font-family: var(--f-display);
    font-weight: 400;
    font-size: clamp(1.55rem, 5.5vw, 2rem);
    line-height: 1.12;
    letter-spacing: -0.02em;
}
.close__p {
    max-width: 30rem;
    margin: 0.6rem 0 0;
    font-size: 0.98rem;
    line-height: 1.6;
    color: var(--on-ink-dim);
}
.getwallet__list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 1.2rem;
}
.wchip {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.45rem 0.9rem 0.45rem 0.5rem;
    border: 1px solid var(--hair-dark);
    border-radius: var(--r);
    font-weight: 700;
    font-size: 0.9rem;
    transition: border-color 0.15s ease, background 0.15s ease, color 0.15s ease;
}
.wchip:hover {
    background: var(--white);
    border-color: var(--white);
    color: var(--black);
}
.wchip__mark {
    display: block;
    width: 26px;
    height: 26px;
    border-radius: 5px;
    object-fit: cover;
}

.host {
    margin-top: 3.4rem;
    padding-top: 2.4rem;
    border-top: 1px solid var(--hair-dark);
}
.host__verb {
    margin: 0 0 0.7rem;
    font: 700 0.72rem var(--f-label);
    letter-spacing: 0.22em;
    text-transform: uppercase;
    color: var(--red);
}

.foot {
    margin-top: 3.4rem;
    padding-top: 2.2rem;
    border-top: 1px solid var(--hair-dark);
}
/* Official Cardano starburst, unaltered — used once, as attribution. */
.foot__built {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    font: 700 0.72rem var(--f-label);
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--on-ink-dim);
}
.foot__built:hover {
    color: var(--white);
}
.foot__star {
    display: block;
    height: 26px;
    width: auto;
}
.foot__logo {
    display: block;
    margin: 1.8rem 0 0;
    color: var(--white);
}
.foot__legal {
    margin: 1rem 0 0;
    font: 400 0.76rem var(--f-label);
    line-height: 1.5;
    color: var(--on-ink-dim);
}

/* ══ Motion — one orchestrated moment on load, nothing else ═════════ */
.hero__l1,
.hero__l2,
.hero__l3,
.hero__sub,
.jump {
    animation: rise 0.6s cubic-bezier(0.16, 0.84, 0.28, 1) both;
}
.hero__l2 {
    animation-delay: 70ms;
}
.hero__l3 {
    animation-delay: 140ms;
}
.hero__sub {
    animation-delay: 230ms;
}
.jump {
    animation-delay: 300ms;
}
@keyframes rise {
    from {
        opacity: 0;
        transform: translateY(0.5em);
    }
    to {
        opacity: 1;
        transform: none;
    }
}
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.25s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
@media (prefers-reduced-motion: reduce) {
    .ada *,
    .ada *::before,
    .ada *::after {
        animation: none !important;
        transition: none !important;
    }
}

/* ══ Wider screens ══════════════════════════════════════════════════ */
@media (min-width: 40rem) {
    .cats {
        grid-template-columns: 1fr 1fr;
        column-gap: 2.5rem;
    }
}
@media (min-width: 62rem) {
    .hero__title {
        font-size: 5.5rem;
    }
}
</style>
