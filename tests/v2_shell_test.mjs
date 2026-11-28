// Contrato de teclado y foco del menú compartido, sin dependencias de navegador.
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import assert from 'node:assert/strict';
class Element {
    constructor() { this.attrs = new Map(); this.handlers = new Map(); this.inert = false; }
    setAttribute(k, v) { this.attrs.set(k, v); }
    removeAttribute(k) { this.attrs.delete(k); }
    addEventListener(k, f) { this.handlers.set(k, f); }
    focus() { document.activeElement = this; }
    get isConnected() { return true; }
    getClientRects() { return [1]; }
    click() { this.handlers.get('click')?.({}); }
}
const side = new Element(), main = new Element(), scrim = new Element(), toggle = new Element();
const first = new Element(), last = new Element();
side.querySelectorAll = () => [first, last];
const classes = new Set(), handlers = new Map();
const document = {
    body: { classList: { contains: (x) => classes.has(x), add: (x) => classes.add(x), remove: (x) => classes.delete(x) } },
    activeElement: toggle,
    getElementById: () => side,
    querySelectorAll: () => [toggle],
    querySelector: (s) => s === '.v2-main' ? main : scrim,
    addEventListener: (k, f) => handlers.set(k, f),
};
let changed;
const media = { matches: true, addEventListener: (k, f) => { changed = f; } };
runInNewContext(readFileSync(new URL('../assets/js/v2_shell.js', import.meta.url), 'utf8'), { document, window: { matchMedia: () => media } });
assert.equal(side.inert, true); assert.equal(toggle.attrs.get('aria-expanded'), 'false');
toggle.click();
assert.equal(side.inert, false); assert.equal(main.inert, true); assert.equal(document.activeElement, first);
assert.equal(toggle.attrs.get('aria-expanded'), 'true');
const key = (k, shift = false) => { let stopped = false; handlers.get('keydown')({ key: k, shiftKey: shift, preventDefault() { stopped = true; } }); return stopped; };
last.focus(); assert.equal(key('Tab'), true); assert.equal(document.activeElement, first);
first.focus(); assert.equal(key('Tab', true), true); assert.equal(document.activeElement, last);
key('Escape');
assert.equal(document.activeElement, toggle); assert.equal(main.inert, false); assert.equal(classes.has('v2-open'), false);
toggle.focus(); toggle.click(); scrim.click(); assert.equal(side.inert, true);
toggle.focus(); toggle.click(); media.matches = false; changed();
assert.equal(side.inert, false); assert.equal(main.inert, false); assert.equal(side.attrs.has('aria-hidden'), false);
console.log('OK Menú móvil: estado accesible, foco, Tab, Escape, cierre exterior y cambio a escritorio.');
