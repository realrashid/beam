<!-- Copied by `beam:install --views`. Yours now — Beam never renders this. -->
<!-- Inertia page: return Inertia::render('Beam/Call', ['token' => $call->token]); -->
<script setup>
import { computed } from 'vue';
import { useBeam } from '@realrashid/beam/vue';

const props = defineProps({ token: { type: String, required: true } });

const { local, remote, state, duration, muted, cameraOn, sharing, error, toggleMute, enableCamera, disableCamera, switchCamera, shareScreen, stopSharing, hangUp } = useBeam(props.token);

const clock = computed(() => `${Math.floor(duration.value / 60)}:${String(duration.value % 60).padStart(2, '0')}`);
</script>

<template>
    <div class="beam-stage">
        <video ref="remote" class="beam-remote" autoplay playsinline />
        <video ref="local" class="beam-local" autoplay muted playsinline />

        <div class="beam-status">{{ state }} · {{ clock }}</div>
        <div v-if="state === 'reconnecting'" class="beam-banner">Reconnecting…</div>
        <div v-if="error" class="beam-banner">{{ error.message }}</div>

        <div class="beam-controls">
            <button type="button" :aria-pressed="muted" @click="toggleMute">{{ muted ? 'Unmute' : 'Mute' }}</button>
            <button type="button" :aria-pressed="!cameraOn" @click="cameraOn ? disableCamera() : enableCamera()">{{ cameraOn ? 'Camera off' : 'Camera on' }}</button>
            <button type="button" @click="switchCamera()">Switch camera</button>
            <button type="button" :aria-pressed="sharing" @click="sharing ? stopSharing() : shareScreen()">{{ sharing ? 'Stop sharing' : 'Share screen' }}</button>
            <button type="button" class="beam-hangup" @click="hangUp()">End</button>
        </div>
    </div>
</template>

<style scoped>
.beam-stage { position: relative; height: 100dvh; display: grid; place-items: center; background: #0f1115; color: #e6e8ec; font: 15px/1.4 system-ui, sans-serif; }
.beam-remote { width: 100%; height: 100%; object-fit: cover; background: #000; }
.beam-local { position: absolute; right: 16px; bottom: 96px; width: 160px; aspect-ratio: 4 / 3; border-radius: 8px; background: #000; object-fit: cover; }
.beam-status { position: absolute; top: 16px; left: 16px; padding: 6px 10px; border-radius: 999px; background: rgba(0, 0, 0, .55); font-variant-numeric: tabular-nums; }
.beam-banner { position: absolute; top: 56px; left: 16px; padding: 6px 10px; border-radius: 6px; background: #b8860b; color: #000; }
.beam-controls { position: absolute; bottom: 24px; left: 50%; transform: translateX(-50%); display: flex; gap: 8px; }
.beam-controls button { padding: 10px 14px; border: 0; border-radius: 999px; background: #2a2f3a; color: inherit; cursor: pointer; }
.beam-controls button[aria-pressed="true"] { background: #e6e8ec; color: #0f1115; }
.beam-controls .beam-hangup { background: #d64545; }
</style>
