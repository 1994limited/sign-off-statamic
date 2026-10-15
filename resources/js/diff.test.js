import { test } from 'node:test';
import assert from 'node:assert/strict';
import { diff, sides } from './diff.js';

const show = (parts) => parts.map(({ type, text }) => (type === 'same' ? text : type === 'added' ? `[+${text}]` : `[-${text}]`)).join('');

test('a changed word is shown removed and added', () => {
    assert.equal(show(diff('Open 9 to 5 on weekdays.', 'Open 8 to 6 on weekdays.')), 'Open [-9][+8] to [-5][+6] on weekdays.');
});

test('neighbouring changes read as one', () => {
    assert.equal(show(diff('Call us today', 'Email our team today')), '[-Call us][+Email our team] today');
});

test('prices change as a whole', () => {
    assert.equal(show(diff('Plans start at £10 a month.', 'Prices are fair.')), '[-Plans start at £10 a month][+Prices are fair].');
});

test('each side keeps its own words', () => {
    const { before, after } = sides('We are the cheapest in the UK.', 'We are good value.');
    assert.equal(before.map((p) => p.text).join(''), 'We are the cheapest in the UK.');
    assert.equal(after.map((p) => p.text).join(''), 'We are good value.');
});

test('empty and missing sides', () => {
    assert.equal(show(diff(null, 'New page')), '[+New page]');
    assert.equal(show(diff('Gone', '')), '[-Gone]');
    assert.deepEqual(diff('', ''), []);
});

test('lines and accents are kept', () => {
    assert.equal(show(diff('Café\nopen', 'Café\nclosed')), 'Café\n[-open][+closed]');
});

test('very long texts still compare', () => {
    const long = Array.from({ length: 3000 }, (_, i) => `word${i}`).join(' ');
    const parts = diff(long, long.replace('word1500', 'changed'));
    assert.equal(show(parts).includes('[-word1500][+changed]'), true);
});
