const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/js/purchase-order-editor.js', 'utf8');
const calculation = source.slice(source.indexOf('const calculate = () => {'), source.indexOf('const reindex = () => {'));
for (const [code, total] of [['IDR', 12], ['USD', 11.1]]) {
    test(`PO ${code} sums rounded line taxes, not tax on document sum`, () => {
        const fields = {};
        const row = () => ({querySelector: selector => selector.includes('quantity') ? {value: 1} : selector.includes('unit_price_amount') ? {value: 5} : {textContent: ''}});
        const context = {currency: {value: code}, rate: () => 11, additionTax: {}, deductionTax: null,
            rows: () => [row(), row()], parseNumber: Number, discount: {value: 0}, shipping: {value: 0},
            formatMoney: v => v, updateCurrencyPrefixes: () => {}, root: {querySelector: s => fields[s] ||= {}}};
        context.rate = select => select ? 11 : 0;
        vm.runInNewContext(calculation + 'calculate();', context);
        assert.ok(Math.abs(fields['[data-summary-total]'].textContent - total) < 0.000001);
    });
}
