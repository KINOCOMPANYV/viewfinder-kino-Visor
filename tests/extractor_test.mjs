// Prueba del Extractor con una cotización real (texto del PDF 106776, datos del cliente cambiados).
// Uso: node tests/extractor_test.mjs
import { readFileSync } from 'node:fs';
import { looksLikeCode, parseTable, parseOrder } from '../assets/js/extractor_core.js';

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

// Recibo c-5921: descripción en dos líneas y la cantidad «1x» en la segunda («1967G-4 (C18) … PLA-» / «1x TA/NEGRO …»)
const L = (text, cells) => ({ page: 1, text, cells: cells || [text], bold: (cells || [text]).map(() => false) });
const rec = parseOrder([
    L('Recibo #c-5921'), L('3 artículos'),
    L('5x 674B-1 POEDAGAR $430,000.00', ['5x 674B-1 POEDAGAR', '$430,000.00']),
    L('1967G-4 (C18) RELOJ JOEFOX BICOLOR PLA-'),
    L('1x TA/NEGRO TIPO INVICTA ANA-DIG METÁLICO $130,000.00', ['1x TA/NEGRO TIPO INVICTA ANA-DIG METÁLICO', '$130,000.00']),
    L('2x 810-C1 RELOJ $100,000.00', ['2x 810-C1 RELOJ', '$100,000.00']),
    L('Total: $660,000.00'), L('Cant: 8'),
]);
const rb = Object.fromEntries(rec.items.map((it) => [it.code, it]));
ok(rb['1967G-4'] && rb['1967G-4'].qty === 1 && rb['1967G-4'].price === 130000, '1967G-4 × 1 a 130,000 (cantidad en la 2.ª línea)');
ok(rec.items.length === 3 && !rb['1x'], '3 artículos, sin código falso «1x»');
ok(rec.order.totalOk === true && rec.warnings.length === 0, 'recibo cuadra sin avisos (' + (rec.warnings.join(' | ') || 'ninguno') + ')');
console.log(r.items.length + ' artículos, ' + r.order.unitsFound + ' unidades. Avisos: ' + (r.warnings.join(' | ') || 'ninguno'));
process.exit(fail ? 1 : 0);
