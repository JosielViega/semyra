'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const createNoiseSuppression = require('../../public/assets/js/room-noise-suppression.js');
const FrameBuffer = require('../../public/assets/vendor/rnnoise/rnnoise-frame-buffer.js');

test('buffers 128-sample worklet blocks into deterministic 480-sample RNNoise frames', () => {
    const buffer = new FrameBuffer();
    const blocks = Array.from({length: 8}, (_, block) => Float32Array.from({length: 128}, (_, index) => block * 128 + index));
    const outputs = blocks.map(() => new Float32Array(128));
    let frames = 0;
    blocks.forEach((input, index) => buffer.process(input, outputs[index], (frame, processed) => {
        frames += 1;
        for (let sample = 0; sample < frame.length; sample += 1) processed[sample] = frame[sample] * 0.5;
    }));
    assert.equal(frames, 2);
    const combined = Float32Array.from(outputs.flatMap((output) => Array.from(output)));
    assert.equal(combined.slice(0, 384).every((sample) => sample === 0), true);
    assert.deepEqual(Array.from(combined.slice(384, 864)), Array.from({length: 480}, (_, index) => index * 0.5));
});

test('rejects unsupported environments before requesting a microphone', async () => {
    const api = createNoiseSuppression({navigator: {mediaDevices: {}}});
    assert.equal(api.isSupported(), false);
    await assert.rejects(() => api.create({inputTrack: {stop() {}}, workletUrl: '/worklet.js'}), /rnnoise_unsupported/);
});

test('creates a mono worklet graph and releases every resource exactly once', async () => {
    const calls = [];
    const outputTrack = {contentHint: '', stops: 0, stop() { this.stops += 1; }};
    const inputTrack = {stops: 0, stop() { this.stops += 1; }};
    class AudioContext {
        constructor(options) { this.options = options; this.sampleRate = 48000; this.state = 'suspended'; }
        audioWorklet = {addModule: async (url) => calls.push(['module', url])};
        createMediaStreamSource() { return {connect: (node) => calls.push(['source-connect', node]), disconnect: () => calls.push(['source-disconnect'])}; }
        createMediaStreamDestination() { return {stream: {getAudioTracks: () => [outputTrack]}, disconnect: () => calls.push(['destination-disconnect'])}; }
        async resume() { this.state = 'running'; calls.push(['resume']); }
        async close() { this.state = 'closed'; calls.push(['close']); }
    }
    class AudioWorkletNode {
        constructor(context, name, options) {
            calls.push(['node', name, options]);
            this.port = {
                postMessage: (message) => calls.push(['port-message', message]), close: () => calls.push(['port-close']),
            };
            queueMicrotask(() => this.port.onmessage({data: {type: 'ready'}}));
        }
        connect() { calls.push(['worklet-connect']); }
        disconnect() { calls.push(['worklet-disconnect']); }
    }
    class MediaStream { constructor(tracks) { this.tracks = tracks; } }
    const window = {
        WebAssembly, AudioContext, AudioWorkletNode, MediaStream,
        navigator: {mediaDevices: {getUserMedia() {}}},
        setTimeout, clearTimeout,
    };
    const api = createNoiseSuppression(window);
    const pipeline = await api.create({inputTrack, workletUrl: '/assets/rnnoise-worklet.js'});
    assert.equal(pipeline.track, outputTrack);
    assert.equal(outputTrack.contentHint, 'speech');
    const nodeOptions = calls.find(([type]) => type === 'node')[2];
    assert.deepEqual(nodeOptions.outputChannelCount, [1]);
    assert.equal(nodeOptions.channelCount, 1);
    await pipeline.stop();
    await pipeline.stop();
    assert.equal(inputTrack.stops, 1);
    assert.equal(outputTrack.stops, 1);
    assert.equal(calls.filter(([type]) => type === 'close').length, 1);
});
