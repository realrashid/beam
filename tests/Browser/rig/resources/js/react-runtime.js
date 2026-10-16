// Bundled by esbuild into public/vendor/react-esm.js for the React adapter rig
// page (React 19 ships no browser ESM build). React is CommonJS, so `export *`
// yields nothing — the named exports have to be spelled out.
import React from 'react';
import { createRoot } from 'react-dom/client';

export default React;
export { createRoot };
export const {
    Children, Component, Fragment, Profiler, PureComponent, StrictMode, Suspense,
    cloneElement, createContext, createElement, createRef, forwardRef, isValidElement, lazy, memo,
    startTransition, use, useCallback, useContext, useDebugValue, useDeferredValue, useEffect, useId,
    useImperativeHandle, useInsertionEffect, useLayoutEffect, useMemo, useReducer, useRef, useState,
    useSyncExternalStore, useTransition, version,
} = React;
