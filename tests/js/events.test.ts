import { describe, expect, it, vi } from 'vitest';
import { Emitter } from '../../resources/js/events';

type Events = { hello: [string]; count: [number, number]; empty: [] };

describe('Emitter', () => {
    it('delivers arguments to a listener', () => {
        const e = new Emitter<Events>();
        const fn = vi.fn();
        e.on('hello', fn);
        e.emit('hello', 'world');
        expect(fn).toHaveBeenCalledWith('world');
    });

    it('once fires exactly once', () => {
        const e = new Emitter<Events>();
        const fn = vi.fn();
        e.once('count', fn);
        e.emit('count', 1, 2);
        e.emit('count', 3, 4);
        expect(fn).toHaveBeenCalledTimes(1);
        expect(fn).toHaveBeenCalledWith(1, 2);
    });

    it('off with a function removes only that listener', () => {
        const e = new Emitter<Events>();
        const a = vi.fn(), b = vi.fn();
        e.on('hello', a);
        e.on('hello', b);
        e.off('hello', a);
        e.emit('hello', 'x');
        expect(a).not.toHaveBeenCalled();
        expect(b).toHaveBeenCalledTimes(1);
    });

    it('off without a function removes every listener for the event', () => {
        const e = new Emitter<Events>();
        const a = vi.fn(), b = vi.fn();
        e.on('hello', a);
        e.on('hello', b);
        e.off('hello');
        e.emit('hello', 'x');
        expect(a).not.toHaveBeenCalled();
        expect(b).not.toHaveBeenCalled();
    });

    it('the returned unsubscribe function works', () => {
        const e = new Emitter<Events>();
        const fn = vi.fn();
        const off = e.on('empty', fn);
        off();
        e.emit('empty');
        expect(fn).not.toHaveBeenCalled();
    });

    it('a throwing listener does not block the next one', () => {
        const e = new Emitter<Events>();
        const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
        const second = vi.fn();
        e.on('hello', () => { throw new Error('boom'); });
        e.on('hello', second);
        expect(() => e.emit('hello', 'x')).not.toThrow();
        expect(second).toHaveBeenCalledTimes(1);
        expect(spy).toHaveBeenCalledTimes(1);
        spy.mockRestore();
    });

    it('emitting with no listeners is a no-op, and removeAll clears everything', () => {
        const e = new Emitter<Events>();
        expect(() => e.emit('hello', 'x')).not.toThrow();
        e.on('hello', () => {});
        e.on('count', () => {});
        e.removeAll();
        expect(e.listenerCount('hello')).toBe(0);
        expect(e.listenerCount('count')).toBe(0);
    });
});
