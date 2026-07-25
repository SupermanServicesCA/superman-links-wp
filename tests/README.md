# Plugin tests

Standalone PHP harnesses — no WordPress, no DB, no network. They stub just
enough of WP to load `includes/class-api.php` and drive the private methods
through reflection.

```bash
php tests/test-internal-link-walk.php    # widget walk, allowlist, guards, delete symmetry
php tests/test-insert-no-phantom.php     # end-to-end insert_link_elementor behaviour
```

Both exit non-zero on failure.

## Why these exist

`test-insert-no-phantom.php` is the regression guard for the v2.3.0 rule:

> **On an Elementor page with a valid, non-empty element tree, `post_content` is
> never written.**

Before v2.3.0 three paths broke that rule and produced "phantom" links — the
endpoint returned `success: true`, the CRM recorded the link as live, and
nothing ever rendered, because Elementor paints `_elementor_data` and not
`post_content`. One of them (the append path) phantomed unconditionally. These
went undetected for weeks at a time (S211d: one link was phantom from April to
July).

Case **A** reproduces the exact original failure: the sentence exists only in an
unsupported widget *and* `post_content` carries a dead copy of it. That
combination is what made the old code's wrap "succeed". Case **G** proves the one
legitimate `post_content` write — a page with no Elementor tree at all — still
works, so the fix didn't over-correct.

If you widen `internal_link_wrappable_fields()`, add a case to
`test-internal-link-walk.php` and re-run both. If a change makes any
`post_content NOT written` assertion fail, you have reintroduced the phantom.
