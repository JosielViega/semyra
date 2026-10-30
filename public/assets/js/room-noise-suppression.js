'use strict';

((root, factory) => {
    const api = factory(root);
    if (typeof module === 'object' && module.exports) module.exports = factory;
    else root.SemyraNoiseSuppression = api;
})(typeof window !== 'undefined' ? window : globalThis, (window) => {
    const isSupported = () => Boolean(
        window.WebAssembly
        && window.isSecureContext !== false
        && (window.AudioContext || window.webkitAudioContext)
        && window.AudioWorkletNode
        && window.MediaStream
        && window.navigator?.mediaDevices?.getUserMedia
    );

    const create = async ({inputTrack, workletUrl, timeoutMs = 8000, onError = () => {}}) => {
        if (!isSupported()) throw new Error('rnnoise_unsupported');
        if (!inputTrack || typeof inputTrack.stop !== 'function') throw new Error('invalid_microphone_track');
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        let context = null;
        let source = null;
        let worklet = null;
        let destination = null;
        let outputTrack = null;
        let stopped = false;
        const trackEnded = () => onError(new Error('microphone_ended'));
        inputTrack.addEventListener?.('ended', trackEnded, {once: true});
        const stop = async () => {
            if (stopped) return;
            stopped = true;
            try { worklet?.port?.postMessage({type: 'stop'}); } catch { }
            try { source?.disconnect(); } catch { }
            try { worklet?.disconnect(); } catch { }
            try { destination?.disconnect(); } catch { }
            try { worklet?.port?.close(); } catch { }
            try { inputTrack.removeEventListener?.('ended', trackEnded); } catch { }
            try { outputTrack?.stop(); } catch { }
            try { inputTrack.stop(); } catch { }
            if (context && context.state !== 'closed') {
                try { await context.close(); } catch { }
            }
        };
        try {
            context = new AudioContext({sampleRate: 48000, latencyHint: 'interactive'});
            if (context.sampleRate !== 48000) throw new Error('rnnoise_requires_48khz');
            await context.audioWorklet.addModule(workletUrl);
            source = context.createMediaStreamSource(new window.MediaStream([inputTrack]));
            destination = context.createMediaStreamDestination();
            try {
                destination.channelCount = 1;
                destination.channelCountMode = 'explicit';
            } catch { }
            worklet = new window.AudioWorkletNode(context, 'semyra-rnnoise', {
                numberOfInputs: 1,
                numberOfOutputs: 1,
                outputChannelCount: [1],
                channelCount: 1,
                channelCountMode: 'explicit',
                channelInterpretation: 'speakers',
            });
            await new Promise((resolve, reject) => {
                const timer = window.setTimeout(() => reject(new Error('rnnoise_timeout')), timeoutMs);
                worklet.port.onmessage = (event) => {
                    if (event.data?.type === 'ready') {
                        window.clearTimeout(timer);
                        resolve();
                    } else if (event.data?.type === 'error') {
                        window.clearTimeout(timer);
                        reject(new Error(event.data.code ?? 'rnnoise_initialization_failed'));
                    }
                };
            });
            worklet.port.onmessage = (event) => {
                if (event.data?.type === 'error') onError(new Error(event.data.code ?? 'rnnoise_processing_failed'));
            };
            source.connect(worklet);
            worklet.connect(destination);
            if (context.state === 'suspended') await context.resume();
            outputTrack = destination.stream.getAudioTracks()[0] ?? null;
            if (!outputTrack) throw new Error('rnnoise_output_missing');
            if ('contentHint' in outputTrack) outputTrack.contentHint = 'speech';
            return {track: outputTrack, stop, inputTrack, context};
        } catch (error) {
            await stop();
            throw error;
        }
    };

    return {isSupported, create};
});
