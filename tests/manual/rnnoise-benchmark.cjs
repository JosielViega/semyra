'use strict';

const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {pathToFileURL} = require('node:url');
const {performance} = require('node:perf_hooks');

(async () => {
    const source = path.resolve(__dirname, '../../public/assets/vendor/rnnoise/rnnoise-sync.js');
    const temporary = path.join(os.tmpdir(), `semyra-rnnoise-${process.pid}.mjs`);
    fs.copyFileSync(source, temporary);
    try {
        const memoryBefore = process.memoryUsage().heapUsed;
        const initializationStarted = performance.now();
        const {default: createModule} = await import(`${pathToFileURL(temporary).href}?v=${Date.now()}`);
        const module = createModule();
        const state = module._rnnoise_create();
        const pointer = module._malloc(480 * Float32Array.BYTES_PER_ELEMENT);
        const initializationMs = performance.now() - initializationStarted;
        const offset = pointer / Float32Array.BYTES_PER_ELEMENT;
        for (let index = 0; index < 480; index += 1) {
            const voice = Math.sin((2 * Math.PI * 220 * index) / 48000) * 6000;
            const noise = ((index * 7919) % 1024 - 512) * 2;
            module.HEAPF32[offset + index] = voice + noise;
        }
        const frames = 1000;
        const processingStarted = performance.now();
        for (let frame = 0; frame < frames; frame += 1) {
            module._rnnoise_process_frame(state, pointer, pointer);
        }
        const processingMs = performance.now() - processingStarted;
        const memoryAfter = process.memoryUsage().heapUsed;
        const finite = module.HEAPF32.subarray(offset, offset + 480).every(Number.isFinite);
        module._rnnoise_destroy(state);
        module._free(pointer);
        process.stdout.write(`${JSON.stringify({
            assetBytes: fs.statSync(source).size,
            initializationMs: Number(initializationMs.toFixed(2)),
            frames,
            frameDurationMs: 10,
            processingMs: Number(processingMs.toFixed(2)),
            processingMsPerFrame: Number((processingMs / frames).toFixed(4)),
            approximateHeapDeltaBytes: memoryAfter - memoryBefore,
            finiteOutput: finite,
        }, null, 2)}\n`);
        if (!finite) process.exitCode = 1;
    } finally {
        fs.rmSync(temporary, {force: true});
    }
})().catch((error) => {
    process.stderr.write(`${error.stack ?? error}\n`);
    process.exitCode = 1;
});
