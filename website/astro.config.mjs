import { unified } from '@astrojs/markdown-remark';
import sitemap from '@astrojs/sitemap';
import { defineConfig } from 'astro/config';

import { rewriteDocumentationLinks } from './src/lib/remark-documentation-links.mjs';
import { scrollableTables } from './src/lib/rehype-scrollable-tables.mjs';

export default defineConfig({
  site: 'https://ben-challis.github.io',
  base: '/greenlight',
  trailingSlash: 'always',
  integrations: [sitemap()],
  markdown: {
    processor: unified({
      remarkPlugins: [[rewriteDocumentationLinks, { base: '/greenlight' }]],
      rehypePlugins: [scrollableTables],
    }),
    shikiConfig: {
      langAlias: {
        neon: 'yaml',
      },
      theme: 'github-light',
      wrap: true,
    },
  },
});
