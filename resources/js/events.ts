/**
 * A typed event emitter — the base of Call, Transfer, Peer and Signaling.
 *
 * E maps event names to their listener argument tuples, so
 *   call.on('ended', ({ reason, duration }) => …)
 * is checked by the compiler, and a typo in an event name is an error rather
 * than a listener that never fires.
 */
export type Listener<A extends unknown[]> = (...args: A) => void;

export class Emitter<E extends Record<string, unknown[]>> {
    private listeners = new Map<keyof E, Set<Listener<any>>>();

    /** Subscribe. Returns an unsubscribe function. */
    on<K extends keyof E>(event: K, fn: Listener<E[K]>): () => void {
        let set = this.listeners.get(event);
        if (!set) {
            set = new Set();
            this.listeners.set(event, set);
        }
        set.add(fn);

        return () => this.off(event, fn);
    }

    /** Subscribe for exactly one delivery. */
    once<K extends keyof E>(event: K, fn: Listener<E[K]>): () => void {
        const wrapped: Listener<E[K]> = (...args) => {
            this.off(event, wrapped);
            fn(...args);
        };

        return this.on(event, wrapped);
    }

    /** Remove one listener, or every listener for the event when fn is omitted. */
    off<K extends keyof E>(event: K, fn?: Listener<E[K]>): void {
        if (!fn) {
            this.listeners.delete(event);
            return;
        }
        this.listeners.get(event)?.delete(fn);
    }

    /**
     * Deliver to every listener. A listener that throws must not stop the
     * others — a broken UI callback should not silently kill a `bye`.
     */
    emit<K extends keyof E>(event: K, ...args: E[K]): void {
        const set = this.listeners.get(event);
        if (!set) return;

        // Copy first: a listener may unsubscribe itself (once()) or others.
        for (const fn of [...set]) {
            try {
                fn(...args);
            } catch (error) {
                console.error(`Beam: listener for "${String(event)}" threw`, error);
            }
        }
    }

    removeAll(): void {
        this.listeners.clear();
    }

    /** How many listeners an event has — for tests and for adapters deciding whether to bridge. */
    listenerCount<K extends keyof E>(event: K): number {
        return this.listeners.get(event)?.size ?? 0;
    }
}
