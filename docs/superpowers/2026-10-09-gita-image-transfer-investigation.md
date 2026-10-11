# Gitashop image-transfer investigation

## Confirmed evidence

The reported creation run stopped before parent submission. Seventeen of eighteen planned images had accepted image IDs; the pending image was the size-chart image with scene `desc`. A GET-only probe through the production downloader succeeded: the HTTP content type and decoded image were JPEG, 44,643 bytes, 543 by 1,176 pixels. This proves current source download availability, not historical upload acceptance.

Other saved runs contain accepted `desc` image uploads. The scene alone is therefore not established as the cause. The official upload contract permits `normal` and `desc`; substituting `normal` would apply square image processing and could alter the source size chart.

The original `upload()` caught every exception, including download failure, HTTP/API rejection and missing acceptance fields, and replaced it with the same generic transfer message. It did not retain the actual rejection code. The underlying reason cannot be recovered from that saved message.

## Diagnostic correction

Preserve the downloader's fixed safe message. Separate upload connection errors, HTTP/API rejection and malformed acceptance. Expose only a bounded error code, HTTP status where relevant, and fixed guidance classified from response text; never expose raw messages, credentials or URLs. Inspect per-image rejection as well as the top-level envelope. Keep response acceptance requirements, account binding and existing bounded transient retries.

Label failed image transfers with their image number. No source-image transformations, omitted charts, guessed replacement images, SKU changes or product mutation retries are introduced.

## Verification and remaining work

- Three HTTP-boundary regressions failed against the original generic masking and passed after the correction: 3 tests, 147 assertions. They cover source-download identification, sanitized top-level upload rejection, and per-image rejection without parent creation.
- The Gita suites passed: 106 tests, 7,976 assertions.
- PHP syntax and git diff checks passed.
- Independent read-only review found no blocking issues.
- Full backend verification passed: 799 tests, 13,674 assertions. Review identified a minor image-numbering edge after the final successful transfer; numbering was narrowed to the transfer call only. Follow-up review found no remaining issues, and the final affected suite passed again: 106 tests, 7,976 assertions.
- An operator retry was requested after the correction to collect the actual Shopee result. This investigation does not claim that the underlying upload rejection is fixed or that the listing is published.

All live diagnostics used database reads and source-image GET requests. No diagnostic upload, product creation, variant initialization, publication or inventory write was performed.

Follow-up: the operator retry identified a dimensions/ratio rejection. The confirmed target rules and padding correction are documented in [2026-10-10-gita-size-chart-padding-verification.md](2026-10-10-gita-size-chart-padding-verification.md).
