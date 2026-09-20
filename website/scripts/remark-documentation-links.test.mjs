import assert from 'node:assert/strict';
import test from 'node:test';

import { rewriteDocumentationLinks } from '../src/lib/remark-documentation-links.mjs';

function transform(url) {
  const link = { type: 'link', url, children: [] };
  const tree = { type: 'root', children: [{ type: 'paragraph', children: [link] }] };
  rewriteDocumentationLinks({ base: '/greenlight' })(tree);
  return link.url;
}

test('external Markdown links retain their complete URL', () => {
  const urls = [
    'https://github.com/hyperf/hyperf/blob/3.2/docs/en/coroutine.md',
    'https://example.com/guide.md?plain=1#setup',
    'http://example.com/guide.md',
    '//example.com/guide.md#setup',
    'custom+docs://example.com/guide.md',
  ];

  for (const url of urls) {
    assert.equal(transform(url), url);
  }
});

test('relative guide links resolve to website pages and retain fragments', () => {
  assert.equal(transform('configuration.md#workers'), '/greenlight/docs/configuration/#workers');
  assert.equal(transform('./getting-started.md'), '/greenlight/docs/getting-started/');
});

test('architecture links resolve to repository sources', () => {
  assert.equal(
    transform('architecture/jsonl.md#events'),
    'https://github.com/ben-challis/greenlight/blob/main/docs/architecture/jsonl.md#events',
  );
});
