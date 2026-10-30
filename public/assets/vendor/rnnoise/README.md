# RNNoise WebAssembly assets

Semyra vendors the synchronous AudioWorklet build from
[`jitsi/rnnoise-wasm`](https://github.com/jitsi/rnnoise-wasm) at commit
`cb529a59a8478fe604e57986fc96afdaecfa6fb7` (package line 0.2.1).
That build embeds the WebAssembly binary, so no network request to a CDN or
separate `.wasm` file is required after the local JavaScript asset is loaded.

The embedded RNNoise source is the Xiph project at commit
`372f7b4b76cde4ca1ec4605353dd17898a99de38`.

## Integrity

- `rnnoise-sync.js`: SHA-256 `05a553f523d59502d133a6d05dbf1878137c9e7bcff06edf5561f7001b62f95f`
- `LICENSE-jitsi-rnnoise-wasm.txt`: SHA-256 `4646d0ab74a05730f8b3b12b6376ddccb7f0905ee5b69265b3d873b97ace3e94`
- `LICENSE-xiph-rnnoise.txt`: SHA-256 `45d37ca1cdb278c088e1aa85e0e65ca3a534ed86a28dcc96ca16810248a61d35`

The Jitsi wrapper is distributed under Apache-2.0. Xiph RNNoise is distributed
under BSD-3-Clause; both license notices are retained in this directory.

`rnnoise-worklet.js` and `rnnoise-frame-buffer.js` are Semyra integration code,
not upstream files. They enforce mono, 48 kHz input and 480-sample RNNoise
frames. Browser echo cancellation stays enabled before this worklet; native
browser noise suppression and automatic gain control are disabled in enhanced
mode to avoid duplicate processing.
