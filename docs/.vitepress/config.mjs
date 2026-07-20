import { defineConfig } from 'vitepress'

// Onboard.Ninja documentation site.
// Deployed to GitHub Pages on the public platform repo (cardano-onboard/platform).
// `base` is set for a project Pages site (https://<org>.github.io/platform/).
// If you serve from a custom domain (e.g. docs.onbd.io), change base to '/'.
export default defineConfig({
  title: 'Onboard.Ninja',
  description: 'Run Cardano token airdrops — create campaigns, generate claim codes, and let recipients claim rewards to their wallet.',
  base: '/platform/',
  lastUpdated: true,
  cleanUrls: true,
  // The reports include illustrative localhost URLs (load-test targets); don't
  // treat those as broken links. Real internal links are still checked.
  ignoreDeadLinks: [/^https?:\/\/localhost/],

  themeConfig: {
    nav: [
      { text: 'Guide', link: '/guide/introduction' },
      { text: 'API', link: '/guide/api-reference' },
      {
        text: 'Reference',
        items: [
          { text: 'Editions (SaaS vs Self-hosted)', link: '/guide/editions' },
          { text: 'Coverage Report', link: '/coverage-report' },
          { text: 'Security & Load Testing', link: '/security-and-load-testing' },
          { text: 'Brand & Theme', link: '/brand-style-guide' },
        ],
      },
    ],

    sidebar: {
      '/guide/': [
        {
          text: 'Getting Started',
          items: [
            { text: 'Introduction', link: '/guide/introduction' },
            { text: 'Sign up (SaaS)', link: '/guide/getting-started-saas' },
            { text: 'Install (Self-hosted)', link: '/guide/getting-started-self-hosted' },
            { text: 'Configuration', link: '/guide/configuration' },
          ],
        },
        {
          text: 'Using the Platform',
          items: [
            { text: 'Campaigns & funding', link: '/guide/campaigns' },
            { text: 'Codes & reward tokens', link: '/guide/codes-and-rewards' },
            { text: 'QR codes & claiming', link: '/guide/claiming' },
            { text: 'Monitoring & performance', link: '/guide/monitoring' },
          ],
        },
        {
          text: 'Reference',
          items: [
            { text: 'API reference', link: '/guide/api-reference' },
            { text: 'Editions', link: '/guide/editions' },
          ],
        },
      ],
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/cardano-onboard/platform' },
    ],

    search: { provider: 'local' },

    footer: {
      message: 'Released under the Apache License 2.0.',
      copyright: 'Onboard.Ninja — Cardano airdrop & claim platform.',
    },

    editLink: {
      pattern: 'https://github.com/cardano-onboard/platform/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },
  },
})
