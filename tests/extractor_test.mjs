// Prueba del Extractor con una cotización real (texto del PDF 106776, datos del cliente cambiados).
// Uso: node tests/extractor_test.mjs
import { readFileSync } from 'node:fs';
import { looksLikeCode, parseTable } from '../assets/js/extractor_core.js';

let fail = 0;
const ok = (cond, msg) => { console.log((cond ? 'OK   ' : 'FALLA') + ' ' + msg); if (!cond) fail++; };

// Códigos con «+» (estuche pareja) cuentan como un solo código; un «+» suelto no
ok(looksLikeCode('S-640+S-647', false), 'S-640+S-647 es código');
ok(!looksLikeCode('S-640+', false), '«S-640+» no es código');
ok(!looksLikeCode('+S-640', false), '«+S-640» no es código');
ok(looksLikeCode('NO.2519A-5', false), 'NO.2519A-5 es código');

const lines = JSON.parse(readFileSync(new URL('./fixtures/cotizacion_106776.json', import.meta.url), 'utf8'));
const r = parseTable(lines);
ok(r !== null, 'se reconoce como cotización en tabla');
const by = Object.fromEntries(r.items.map((it) => [it.code, it]));
ok(by['S-640+S-647'] && by['S-640+S-647'].qty === 10 && by['S-640+S-647'].price === 22000, 'S-640+S-647 × 10 a 22,000');
ok(by['S-640E'] && by['S-640E'].qty === 7, 'S-640E × 7');
ok(by['S-647E'] && by['S-647E'].qty === 8, 'S-647E × 8');
ok(by['NO.2519A-5'] && by['NO.2519A-9'], 'NO.2519A-5 y NO.2519A-9');
ok(r.order.total === 4964000, 'TOTAL del PDF 4,964,000 (leído: ' + r.order.total + ')');
ok(r.order.totalOk === true, 'la suma de las líneas coincide con el TOTAL');
ok(!r.map.some((m) => m.tag === '⚠ línea sin leer'), 'ninguna línea sin leer');
console.log(r.items.length + ' artículos, ' + r.order.unitsFound + ' unidades. Avisos: ' + (r.warnings.join(' | ') || 'ninguno'));
process.exit(fail ? 1 : 0);
