import { defineConfig } from 'vitepress';

export default defineConfig({
  base: '/laravel-atomic-cache/',
  title: 'Laravel Atomic Cache',
  description: 'Stampede-proof caching and invalidation for Laravel.',
  cleanUrls: true,
  lastUpdated: true,

  markdown: {
    lineNumbers: true
  },

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/laravel-atomic-cache/logo.svg' }],
    ['meta', { name: 'theme-color', content: '#0ea5e9' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: 'Laravel Atomic Cache' }],
    ['meta', { property: 'og:description', content: 'Stampede-proof caching, virtual tagging, and transaction-safe invalidation for Laravel.' }]
  ],

  themeConfig: {
    siteTitle: 'Atomic Cache',
    logo: '/logo.svg',

    outline: {
      level: [2, 3],
      label: 'On this page'
    },

    editLink: {
      pattern: 'https://github.com/kaziomarsh24/laravel-atomic-cache/edit/main/docs/:path',
      text: 'Edit this page on GitHub'
    },

    docFooter: {
      prev: 'Previous page',
      next: 'Next page'
    },

    lastUpdated: {
      text: 'Last updated',
      formatOptions: {
        dateStyle: 'medium',
        timeStyle: 'short'
      }
    },

    nav: [
      { text: 'Docs', link: '/guide/overview' },
      { text: 'Quickstart', link: '/guide/quickstart' },
      { text: 'Architecture', link: '/guide/layer-1-core' },
      {
        text: 'v1.5',
        items: [
          { text: 'Changelog', link: 'https://github.com/kaziomarsh24/laravel-atomic-cache/blob/main/CHANGELOG.md' },
          { text: 'Packagist Release', link: 'https://packagist.org/packages/thekaziomar/laravel-atomic-cache' },
          { text: 'GitHub Source', link: 'https://github.com/kaziomarsh24/laravel-atomic-cache' }
        ]
      }
    ],

    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Overview & Philosophy', link: '/guide/overview' },
          { text: 'Installation & Setup', link: '/guide/installation' },
          { text: '10-Second Quickstart', link: '/guide/quickstart' },
          { text: 'Driver Compatibility', link: '/guide/driver-compatibility' },
        ]
      },
      {
        text: 'Architecture Layers',
        items: [
          { text: 'Layer 1: Core Engine', link: '/guide/layer-1-core' },
          { text: 'Layer 2: Model Lifecycle', link: '/guide/layer-2-models' },
          { text: 'Layer 3: Query Macros', link: '/guide/layer-3-queries' },
          { text: 'Layer 4: Service Repositories', link: '/guide/layer-4-services' },
          { text: 'Layer 5: Blade Fragments', link: '/guide/layer-5-blade' },
          { text: 'Layer 6: CLI Diagnostics', link: '/guide/layer-6-cli' },
        ]
      },
      {
        text: 'Reference',
        items: [
          { text: 'Configuration', link: '/guide/configuration' },
          { text: 'Troubleshooting & FAQ', link: '/guide/faq' },
          { text: 'Upgrade & Testing', link: '/guide/upgrade' },
        ]
      }
    ],

    search: {
      provider: 'local',
      options: {
        locales: {
          root: {
            translations: {
              button: {
                buttonText: 'Search docs...',
                buttonAriaLabel: 'Search docs'
              }
            }
          }
        }
      }
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/kaziomarsh24/laravel-atomic-cache' }
    ],

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © 2026 <a href="https://kaziomar.vercel.app" target="_blank" rel="noopener noreferrer" style="color: var(--vp-c-brand-1); font-weight: 500; text-decoration: underline; text-underline-offset: 3px;">Kazi Omar Faruk</a>'
    }
  }
});
