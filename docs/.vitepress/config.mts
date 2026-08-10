import { defineConfig } from 'vitepress'

export default defineConfig({
  title: "TypePHP",
  description: "Runtime Type Enforcement for PHP.",
  base: '/typephp/',
  themeConfig: {
    siteTitle: "TypePHP",
    nav: [
      { text: 'Home', link: '/' },
      { text: 'Documentation', link: '/getting-started/installation' },
      { text: 'Generics', link: '/generics/generics-and-bounds' },
      { text: 'CLI', link: '/getting-started/cli-commands' },
      { text: 'FAQ', link: '/troubleshooting' },
      { text: 'GitHub', link: 'https://github.com/typephp-php/typephp' }
    ],
    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Installation', link: '/getting-started/installation' },
          { text: 'Quick Start', link: '/getting-started/quick-start' },
          { text: 'Configuration', link: '/getting-started/configuration' },
          { text: 'CLI Commands', link: '/getting-started/cli-commands' },
        ]
      },
      {
        text: 'Enforcement Boundaries',
        items: [
          { text: 'Function Contracts', link: '/core-concepts/function-contracts' },
          { text: 'Property Validation', link: '/core-concepts/property-validation' },
          { text: 'Inline Variables', link: '/core-concepts/inline-variables' },
        ]
      },
      {
        text: 'Type Reference',
        items: [
          { text: 'Primitives & Scalars', link: '/supported-types/primitives-and-scalars' },
          { text: 'Arrays & Shapes', link: '/supported-types/arrays-and-shapes' },
          { text: 'Callables & Closures', link: '/supported-types/callables-and-closures' },
          { text: 'Iterators & Generators', link: '/supported-types/iterators-and-generators' },
          { text: 'Unions, Intersections & Conditionals', link: '/supported-types/unions-intersections-and-conditionals' },
          { text: 'Type Aliases', link: '/supported-types/type-aliases' },
        ]
      },
      {
        text: 'Runtime Generics',
        items: [
          { text: 'Generics & Bounds', link: '/generics/generics-and-bounds' },
        ]
      },
      {
        text: 'Advanced & Architecture',
        items: [
          { text: 'How It Works', link: '/advanced/how-it-works' },
          { text: 'Liskov & Inheritance', link: '/advanced/liskov-and-inheritance' },
          { text: 'Vendor Isolation', link: '/advanced/vendor-and-path-filtering' },
          { text: 'Ignore Annotations', link: '/advanced/ignore-annotations' },
          { text: 'Extensions', link: '/advanced/extensions' },
          { text: 'Exception Handling', link: '/advanced/exception-handling' },
        ]
      },
      {
        text: 'Production & Operations',
        items: [
          { text: 'Production Readiness', link: '/production/production-readiness' },
          { text: 'Performance Considerations', link: '/production/performance-considerations' },
        ]
      },
      {
        text: 'Help & Support',
        items: [
          { text: 'Troubleshooting & FAQ', link: '/troubleshooting' },
        ]
      }
    ],
    socialLinks: [
      { icon: 'github', link: 'https://github.com/typephp-php/typephp' }
    ],
    search: {
      provider: 'local'
    }
  }
})