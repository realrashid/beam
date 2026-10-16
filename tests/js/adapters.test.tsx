import { act, StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { createApp, defineComponent, h, nextTick } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Beam from '../../resources/js/beam';
import { Emitter } from '../../resources/js/events';
import { install } from '../../resources/js/jquery';
import { useBeam as useBeamReact } from '../../resources/js/react';
import { useBeam as useBeamVue } from '../../resources/js/vue';

/** A stand-in for a joined Call: an emitter with the surface the adapters touch. */
function fakeCall() {
    const call = Object.assign(new Emitter<any>(), {
        state: 'connecting', isMuted: false, isCameraOn: true, isSharing: false,
        attach: vi.fn(), mute: vi.fn(), unmute: vi.fn(), toggleMute: vi.fn(), cameraOn: vi.fn(), cameraOff: vi.fn(),
        switchCamera: vi.fn(async () => {}), shareScreen: vi.fn(async () => {}), stopSharing: vi.fn(async () => {}), hangUp: vi.fn(async () => {}),
        // Whether letting go ends the call is Beam's business — beam.test.ts holds
        // the holder counting — so the double only records that it happened.
        release: vi.fn(async () => {}),
    });

    return call;
}

beforeEach(() => { (globalThis as any).IS_REACT_ACT_ENVIRONMENT = true; });
afterEach(() => vi.restoreAllMocks());

describe('Vue useBeam', () => {
    it('joins on mount, attaches to the refs, mirrors events into refs, hangs up on unmount', async () => {
        const call = fakeCall();
        vi.spyOn(Beam, 'join').mockResolvedValue(call as any);
        let api!: ReturnType<typeof useBeamVue>;
        const el = document.createElement('div');
        const app = createApp(defineComponent({
            setup() { api = useBeamVue('packed-token'); return () => h('div', [h('video', { ref: api.local }), h('video', { ref: api.remote })]); },
        }));
        app.mount(el);
        await nextTick(); await Promise.resolve(); await Promise.resolve();

        expect(Beam.join).toHaveBeenCalledWith('packed-token', {});
        expect(call.attach).toHaveBeenCalledWith({ local: expect.any(HTMLVideoElement), remote: expect.any(HTMLVideoElement) });

        call.emit('state', 'connected'); call.emit('tick', { seconds: 7 }); call.emit('muted', true); call.emit('sharing', true);
        expect(api.state.value).toBe('connected');
        expect(api.duration.value).toBe(7);
        expect(api.muted.value).toBe(true);
        expect(api.sharing.value).toBe(true);

        api.mute(); api.disableCamera(); await api.shareScreen();
        expect(call.mute).toHaveBeenCalled(); expect(call.cameraOff).toHaveBeenCalled(); expect(call.shareScreen).toHaveBeenCalled();

        app.unmount();
        expect(call.release).toHaveBeenCalled();   // the adapter lets go; Beam decides whether that ends it
    });

    it('surfaces a refused camera as error and state ended', async () => {
        vi.spyOn(Beam, 'join').mockRejectedValue(Object.assign(new Error('refused'), { reason: 'permission-denied' }));
        let api!: ReturnType<typeof useBeamVue>;
        createApp(defineComponent({ setup() { api = useBeamVue('t'); return () => h('div'); } })).mount(document.createElement('div'));
        await Promise.resolve(); await Promise.resolve(); await Promise.resolve();
        expect(api.error.value?.message).toContain('refused');
        expect(api.state.value).toBe('ended');
    });
});

describe('React useBeam', () => {
    it('joins in an effect, attaches to the refs, mirrors events into state, hangs up on cleanup', async () => {
        const call = fakeCall();
        vi.spyOn(Beam, 'join').mockResolvedValue(call as any);
        let api!: ReturnType<typeof useBeamReact>;
        function Screen() { api = useBeamReact('packed-token'); return <div><video ref={api.local} /><video ref={api.remote} /><span>{api.state}:{api.duration}</span></div>; }
        const el = document.createElement('div');
        const root = createRoot(el);
        await act(async () => { root.render(<Screen />); });
        await act(async () => { await Promise.resolve(); });

        expect(Beam.join).toHaveBeenCalledWith('packed-token', {});
        expect(call.attach).toHaveBeenCalledWith({ local: expect.any(HTMLVideoElement), remote: expect.any(HTMLVideoElement) });

        await act(async () => { call.emit('state', 'connected'); call.emit('tick', { seconds: 3 }); });
        expect(el.textContent).toContain('connected:3');
        expect(api.state).toBe('connected');

        api.toggleMute();
        expect(call.toggleMute).toHaveBeenCalled();

        await act(async () => { root.unmount(); });
        expect(call.release).toHaveBeenCalled();   // the adapter lets go; Beam decides whether that ends it
    });

    it('survives StrictMode: the discarded first mount lets go of the call instead of ending it', async () => {
        const call = fakeCall();
        Object.assign(call, { id: 'call_strict' });
        vi.spyOn(Beam, 'join').mockResolvedValue(call as any);
        // release() is what "I am done with this call" means: the last holder
        // hangs up, an earlier one leaves the live mount alone. Hanging up here
        // is what used to end a React call the moment it connected in dev.
        const release = call.release;

        function Screen() { const api = useBeamReact('packed-token'); return <video ref={api.remote} />; }
        const root = createRoot(document.createElement('div'));
        await act(async () => { root.render(<StrictMode><Screen /></StrictMode>); });
        await act(async () => { await Promise.resolve(); });

        expect(release).toHaveBeenCalled();             // the throwaway mount let go
        expect(call.hangUp).not.toHaveBeenCalled();      // and did not end the call the live mount is using

        await act(async () => { root.unmount(); });
        expect(release).toHaveBeenCalledTimes(2);        // the live mount lets go too, and that one is the last holder
    });
});

describe('jQuery $.beam', () => {
    it('installs a namespace and bridges call events onto $(call).on()', async () => {
        const call = fakeCall();
        vi.spyOn(Beam, 'join').mockResolvedValue(call as any);
        const handlers = new Map<object, Map<string, (...a: unknown[]) => void>>();
        const $: any = (target: object) => ({
            on: (name: string, fn: (...a: unknown[]) => void) => { if (!handlers.has(target)) handlers.set(target, new Map()); handlers.get(target)!.set(name, fn); },
            trigger: (name: string, args: unknown[] = []) => handlers.get(target)?.get(name)?.({ type: name }, ...args),
        });

        const beam = install($);
        expect($.beam).toBe(beam);

        const joined = await $.beam.join('tok');
        expect(joined).toBe(call);
        const onEnded = vi.fn();
        $(call).on('ended', onEnded);
        call.emit('ended', { reason: 'hangup', duration: 9 });
        expect(onEnded).toHaveBeenCalledWith({ type: 'ended' }, { reason: 'hangup', duration: 9 });
    });

    it('warns once when jQuery is absent instead of throwing', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        delete (globalThis as any).jQuery;
        await import('../../resources/js/jquery?absent=' + Date.now());
        expect(warn).toHaveBeenCalledWith(expect.stringContaining('jQuery is not on window'));
    });
});
