export type BeamErrorReason =
    | 'permission-denied'
    | 'no-device'
    | 'media-failed'
    | 'screen-unavailable'
    | 'protocol-mismatch'
    | 'expired'
    | 'malformed'
    | 'signaling-failed'
    | 'turn-unavailable'
    | 'connection-failed'
    | 'declined'
    | 'timeout'
    /** play() was refused by the autoplay policy; playback resumes on the next tap. */
    | 'autoplay-blocked'
    /** This browser lacks the feature (setSinkId on Safari, say). */
    | 'unsupported'
    /** A third person arrived in a room that already has two: their signals are ignored. */
    | 'room-full';

/** Every error Beam raises carries a machine-readable reason beside its message. */
export class BeamError extends Error {
    constructor(public readonly reason: BeamErrorReason, message: string) {
        super(message);
        this.name = 'BeamError';
    }
}
