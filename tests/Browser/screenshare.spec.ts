import { expect, test, waitForRemoteVideo } from './fixtures';

test('shareScreen swaps the outgoing video and stopSharing restores the camera', async ({ peers, browserName }) => {
    test.skip(browserName === 'firefox', 'Headless Gecko has no display capture: getDisplayMedia rejects with NotAllowedError, "not allowed by the user agent" (Firefox 155, 20 Sep 2026)');
    test.skip(browserName === 'webkit', 'TODO: WebKit headless getDisplayMedia');
    const { a, b } = peers;
    await a.goto('/login-as/1');
    await b.goto('/login-as/2');
    const room = `share-${Date.now()}`;
    await a.goto(`/room/${room}`);
    await b.goto(`/room/${room}`);
    await waitForRemoteVideo(a, '#them');
    await waitForRemoteVideo(b, '#them');

    const cameraId = await a.evaluate(() => (window as any).__call.localStream.getVideoTracks()[0].id);
    const remoteBefore = await b.evaluate(() => ((document.querySelector('#them') as HTMLVideoElement).srcObject as MediaStream).getVideoTracks()[0].id);

    await a.click('#share');
    await expect.poll(() => a.evaluate(() => (window as any).__beamError ?? null)).toBeNull();
    await expect.poll(() => a.evaluate(() => (window as any).__call.isSharing)).toBe(true);

    // replaceTrack keeps the same remote track id; what changes are the frames. Assert the sender, then that frames still flow.
    const sender = await a.evaluate(() => (window as any).__call.peer.connection.getSenders().find((s: RTCRtpSender) => s.track?.kind === 'video')?.track?.label ?? null);
    expect(sender).not.toBeNull();
    await b.waitForTimeout(1000);
    expect(await b.evaluate(() => (document.querySelector('#them') as HTMLVideoElement).videoWidth)).toBeGreaterThan(0);

    await a.click('#stop-share');
    await expect.poll(() => a.evaluate(() => (window as any).__call.isSharing)).toBe(false);
    expect(await a.evaluate(() => (window as any).__call.peer.connection.getSenders().find((s: RTCRtpSender) => s.track?.kind === 'video')?.track?.id)).toBe(cameraId);
    expect(await b.evaluate(() => ((document.querySelector('#them') as HTMLVideoElement).srcObject as MediaStream).getVideoTracks()[0].id)).toBe(remoteBefore);
});

test('shareScreen({ alongside: true }) sends the screen as a second video track; the camera keeps flowing', async ({ peers, browserName }) => {
    test.skip(browserName === 'firefox', 'Headless Gecko has no display capture');
    test.skip(browserName === 'webkit', 'TODO: WebKit headless getDisplayMedia');
    const { a, b } = peers;
    await a.goto('/login-as/1');
    await b.goto('/login-as/2');
    const room = `share-beside-${Date.now()}`;
    await a.goto(`/room/${room}`);
    await b.goto(`/room/${room}`);
    await waitForRemoteVideo(a, '#them');
    await waitForRemoteVideo(b, '#them');
    const cameraStream = await b.evaluate(() => ((document.querySelector('#them') as HTMLVideoElement).srcObject as MediaStream).id);

    await a.evaluate(() => (window as any).__shareAlongside());
    await expect.poll(() => a.evaluate(() => (window as any).__call.isSharing)).toBe(true);

    // b receives a second video track on a different stream, and its main remote element still shows the camera stream.
    await expect.poll(() => b.evaluate(() => (window as any).__remoteTracks.filter((t: { kind: string }) => t.kind === 'video').length), { timeout: 15_000 }).toBe(2);
    const streams = await b.evaluate(() => (window as any).__remoteTracks.filter((t: { kind: string }) => t.kind === 'video').map((t: { stream: string }) => t.stream));
    expect(new Set(streams).size).toBe(2);
    expect(await b.evaluate(() => ((document.querySelector('#them') as HTMLVideoElement).srcObject as MediaStream).id)).toBe(cameraStream);
    await waitForRemoteVideo(b, '#them');

    await a.click('#stop-share');
    await expect.poll(() => a.evaluate(() => (window as any).__call.isSharing)).toBe(false);
    await expect.poll(() => b.evaluate(() => (window as any).__call.peer.connection.getReceivers().filter((r: RTCRtpReceiver) => r.track.kind === 'video' && r.track.readyState === 'live' && !r.track.muted).length), { timeout: 15_000 }).toBe(1);
    expect(await a.evaluate(() => (window as any).__beamError ?? null)).toBeNull();
});
