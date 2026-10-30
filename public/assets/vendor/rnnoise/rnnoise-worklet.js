import createRNNWasmModuleSync from './rnnoise-sync.js';
import './rnnoise-frame-buffer.js';

const FRAME_SIZE = 480;
const PCM_SCALE = 32768;

class SemyraRnnoiseProcessor extends AudioWorkletProcessor {
    constructor() {
        super();
        this.module = null;
        this.state = 0;
        this.framePointer = 0;
        this.stopped = false;
        this.failed = false;
        this.buffer = new globalThis.SemyraRnnoiseFrameBuffer(FRAME_SIZE, 1920);
        this.port.onmessage = (event) => {
            if (event.data?.type === 'stop') this.destroy();
        };
        try {
            if (sampleRate !== 48000) throw new Error('rnnoise_requires_48khz');
            this.module = createRNNWasmModuleSync();
            this.framePointer = this.module._malloc(FRAME_SIZE * Float32Array.BYTES_PER_ELEMENT);
            this.state = this.module._rnnoise_create();
            if (!this.framePointer || !this.state) throw new Error('rnnoise_initialization_failed');
            this.port.postMessage({type: 'ready', frameSize: FRAME_SIZE, sampleRate});
        } catch (error) {
            this.failed = true;
            this.destroy();
            this.port.postMessage({type: 'error', code: error?.message ?? 'rnnoise_initialization_failed'});
        }
    }

    suppress(input, output) {
        const heapOffset = this.framePointer / Float32Array.BYTES_PER_ELEMENT;
        const heap = this.module.HEAPF32;
        for (let index = 0; index < FRAME_SIZE; index += 1) heap[heapOffset + index] = input[index] * PCM_SCALE;
        this.module._rnnoise_process_frame(this.state, this.framePointer, this.framePointer);
        for (let index = 0; index < FRAME_SIZE; index += 1) output[index] = heap[heapOffset + index] / PCM_SCALE;
    }

    process(inputs, outputs) {
        const input = inputs[0]?.[0];
        const output = outputs[0]?.[0];
        if (!output) return !this.stopped;
        if (!input || this.failed || this.stopped || !this.module) {
            if (input) output.set(input.subarray(0, output.length));
            else output.fill(0);
            return !this.stopped;
        }
        try {
            this.buffer.process(input, output, (frame, processed) => this.suppress(frame, processed));
        } catch (error) {
            this.failed = true;
            output.set(input.subarray(0, output.length));
            this.port.postMessage({type: 'error', code: error?.message ?? 'rnnoise_processing_failed'});
        }
        return !this.stopped;
    }

    destroy() {
        if (this.stopped) return;
        this.stopped = true;
        if (this.module && this.state) this.module._rnnoise_destroy(this.state);
        if (this.module && this.framePointer) this.module._free(this.framePointer);
        this.state = 0;
        this.framePointer = 0;
    }
}

registerProcessor('semyra-rnnoise', SemyraRnnoiseProcessor);
