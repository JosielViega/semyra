'use strict';

((root, factory) => {
    const FrameBuffer = factory();
    root.SemyraRnnoiseFrameBuffer = FrameBuffer;
    if (typeof module === 'object' && module.exports) module.exports = FrameBuffer;
})(typeof globalThis !== 'undefined' ? globalThis : self, () => class SemyraRnnoiseFrameBuffer {
    constructor(frameSize = 480, capacity = 1920) {
        if (!Number.isInteger(frameSize) || frameSize <= 0 || !Number.isInteger(capacity) || capacity < frameSize) {
            throw new RangeError('invalid_frame_buffer_size');
        }
        this.frameSize = frameSize;
        this.inputFrame = new Float32Array(frameSize);
        this.processedFrame = new Float32Array(frameSize);
        this.outputQueue = new Float32Array(capacity);
        this.inputLength = 0;
        this.outputRead = 0;
        this.outputWrite = 0;
        this.outputLength = 0;
    }

    process(input, output, processFrame) {
        const inputLength = input?.length ?? 0;
        for (let index = 0; index < inputLength; index += 1) {
            this.inputFrame[this.inputLength] = input[index];
            this.inputLength += 1;
            if (this.inputLength === this.frameSize) {
                processFrame(this.inputFrame, this.processedFrame);
                this.enqueue(this.processedFrame);
                this.inputLength = 0;
            }
        }
        for (let index = 0; index < output.length; index += 1) {
            output[index] = this.outputLength > 0 ? this.dequeue() : 0;
        }
    }

    enqueue(frame) {
        if (this.outputLength + frame.length > this.outputQueue.length) {
            throw new Error('rnnoise_output_overrun');
        }
        for (let index = 0; index < frame.length; index += 1) {
            this.outputQueue[this.outputWrite] = frame[index];
            this.outputWrite = (this.outputWrite + 1) % this.outputQueue.length;
            this.outputLength += 1;
        }
    }

    dequeue() {
        const sample = this.outputQueue[this.outputRead];
        this.outputRead = (this.outputRead + 1) % this.outputQueue.length;
        this.outputLength -= 1;
        return sample;
    }
});
