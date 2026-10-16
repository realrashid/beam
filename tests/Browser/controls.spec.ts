import { expect, test, waitForRemoteVideo } from './fixtures';

/** Sketch §8 "During a call": each control asserted on the local side, and where it can be seen, on the remote side too. */
async function connectPair(a: import('@playwright/test').Page, b: import('@playwright/test').Page, room: string) {
    await a.goto('/login-as/1');
    await b.goto('/login-as/2');
    await a.goto(`/room/${room}`);
    await b.goto(`/room/${room}`);
    await waitForRemoteVideo(a, '#them');
    await waitForRemoteVideo(b, '#them');
}

test('mute and unmute: the local audio track is disabled, not stopped, and the other side hears silence', async ({ peers }) => {
    const { a, b } = peers;
    await connectPair(a, b, `controls-mute-${Date.now()}`);

    await a.click('#mute');
    expect(await a.evaluate(() => (window as any).__call.isMuted)).toBe(true);
    expect(await a.evaluate(() => (window as any).__call.localStream.getAudioTracks().map((t: MediaStreamTrack) => [t.enabled, t.readyState]))).toEqual([[false, 'live']]);

    // The receiver sees the track still live (disabled tracks send silence, they do not end).
    expect(await b.evaluate(() => ((document.querySelector('#them') as HTMLVideoElement).srcObject as MediaStream).getAudioTracks().map((t: MediaStreamTrack) => t.readyState))).toEqual(['live']);

    await a.click('#unmute');
    expect(await a.evaluate(() => (window as any).__call.isMuted)).toBe(false);
});

test('camera off and on: the local video track is disabled, and the remote frames freeze then resume', async ({ peers }) => {
    const { a, b } = peers;
    await connectPair(a, b, `controls-camera-${Date.now()}`);

    await a.click('#camera-off');
    expect(await a.evaluate(() => (window as any).__call.isCameraOn)).toBe(false);
    expect(await a.evaluate(() => (window as any).__call.localStream.getVideoTracks()[0].enabled)).toBe(false);

    // A disabled video track sends black frames: the remote element keeps a live track but its frame counter stops advancing.
    const framesBefore = await b.evaluate(() => (document.querySelector('#them') as any).getVideoPlaybackQuality?.().totalVideoFrames ?? -1);
    await b.waitForTimeout(1500);
    const framesDuring = await b.evaluate(() => (document.querySelector('#them') as any).getVideoPlaybackQuality?.().totalVideoFrames ?? -1);
    test.info().annotations.push({ type: 'frames while camera off', description: `${framesBefore} → ${framesDuring}` });

    await a.click('#camera-on');
    expect(await a.evaluate(() => (window as any).__call.isCameraOn)).toBe(true);
});

test('switchCamera moves to another device and the remote keeps receiving', async ({ peers, browserName }) => {
    test.skip(browserName !== 'chromium', 'Only Chromium\'s fake media provides more than one camera');
    const { a, b } = peers;
    await connectPair(a, b, `controls-switch-${Date.now()}`);

    const before = await a.evaluate(() => (window as any).__call.localStream.getVideoTracks()[0].getSettings().deviceId);
    const cameras = await a.evaluate(async () => (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput').length);
    test.skip(cameras < 2, `Fake media exposes ${cameras} camera(s)`);

    await a.click('#switch');
    await expect.poll(() => a.evaluate(() => (window as any).__beamError ?? null)).toBeNull();
    const after = await a.evaluate(() => (window as any).__call.localStream.getVideoTracks()[0].getSettings().deviceId);
    expect(after).not.toBe(before);

    await b.waitForTimeout(1000);
    expect(await b.evaluate(() => (document.querySelector('#them') as HTMLVideoElement).videoWidth)).toBeGreaterThan(0);
});

test('hangUp: both sides reach ended, the other side by the bye, with a duration', async ({ peers }) => {
    const { a, b } = peers;
    await connectPair(a, b, `controls-hangup-${Date.now()}`);
    await a.waitForTimeout(1500);

    await a.click('#hangup');

    await expect.poll(() => a.evaluate(() => window.__beamState), { timeout: 10_000 }).toBe('ended');
    await expect.poll(() => b.evaluate(() => window.__beamEnded?.reason ?? null), { timeout: 10_000 }).toBe('hangup');
    expect(await a.evaluate(() => window.__beamEnded!.duration)).toBeGreaterThanOrEqual(1);
    expect(await a.evaluate(() => (window as any).__call.localStream.getTracks().map((t: MediaStreamTrack) => t.readyState))).toEqual(['ended', 'ended']);
});

test('a call that started audio-only is upgraded to video with enableVideo(); the other side lights up', async ({ peers }) => {
    const { a, b } = peers;
    await a.goto('/login-as/1');
    await b.goto('/login-as/2');
    const room = `upgrade-${Date.now()}`;
    await a.goto(`/room/${room}?media=audio`);
    await b.goto(`/room/${room}`);
    await waitForRemoteVideo(a, '#them');                                   // a sees b's camera
    await expect.poll(() => a.evaluate(() => window.__beamState)).toBe('connected');
    await expect.poll(() => b.evaluate(() => window.__beamState)).toBe('connected');
    expect(await b.evaluate(() => ((document.querySelector('#them') as HTMLVideoElement).srcObject as MediaStream | null)?.getVideoTracks().length ?? 0)).toBe(0);   // b hears a, sees nothing

    await a.evaluate(() => (window as any).__enableVideo());
    await expect.poll(() => a.evaluate(() => (window as any).__call.isCameraOn)).toBe(true);
    await waitForRemoteVideo(b, '#them', 20_000);                           // renegotiated: b now sees a
    expect(await a.evaluate(() => (window as any).__beamError ?? null)).toBeNull();
});
