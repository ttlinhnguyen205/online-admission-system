import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const widget = readFileSync(new URL('../../resources/views/livewire/candidate/admission-counseling.blade.php', import.meta.url), 'utf8');
const conversation = readFileSync(new URL('../../resources/views/components/admission/counseling-conversation.blade.php', import.meta.url), 'utf8');
const livewire = readFileSync(new URL('../../vendor/livewire/livewire/dist/livewire.js', import.meta.url), 'utf8');
const evaluatorStart = livewire.indexOf('  function generateFunctionFromString(');
const evaluatorEnd = livewire.indexOf('  function generateEvaluatorFromString(', evaluatorStart);
assert.ok(evaluatorStart >= 0 && evaluatorEnd > evaluatorStart, 'Installed Livewire must expose the Alpine expression compiler');
const compile = new Function('handleError', `var evaluatorMemo = {}; ${livewire.slice(evaluatorStart, evaluatorEnd)}; return generateFunctionFromString;`)((error) => { throw error; });

function attribute(source, name) {
    const value = source.match(new RegExp(`${name}="([^"]*)"`))?.[1];
    assert.notEqual(value, undefined, `Missing ${name}`);
    return value;
}

async function evaluate(expression, scope) {
    return await compile(expression, null)({}, scope);
}

async function initialize(storage) {
    const state = await evaluate(attribute(widget, 'x-data'), { sessionStorage: storage });
    let listener;
    state.$watch = (name, callback) => {
        assert.equal(name, 'chatOpen');
        listener = callback;
    };
    state.init();
    return { state, listener };
}

test('widget expressions compile with the installed Alpine evaluator', () => {
    const directives = /\b(?:x-data|x-init|x-effect|x-show|x-text|x-on:[\w.]+|x-bind:[\w-]+)="([^"]*)"/g;
    for (const source of [widget, conversation]) {
        for (const [, expression] of source.matchAll(directives)) {
            if (!expression.includes('@js(')) {
                assert.doesNotThrow(() => compile(expression, null), expression);
            }
        }
    }
});

test('widget restores its open state and saves close and reopen changes', async () => {
    const storage = new Map([['admission-counseling-open', '1']]);
    const { state, listener } = await initialize({
        getItem: (key) => storage.get(key),
        setItem: (key, value) => storage.set(key, value),
    });
    assert.equal(state.chatOpen, true);
    listener(false);
    assert.equal(storage.get('admission-counseling-open'), '0');
    listener(true);
    assert.equal(storage.get('admission-counseling-open'), '1');
});

test('unavailable session storage does not break initialization or state changes', async () => {
    const { state, listener } = await initialize({
        getItem() { throw new Error('Storage unavailable'); },
        setItem() { throw new Error('Storage unavailable'); },
    });
    assert.equal(state.chatOpen, false);
    assert.doesNotThrow(() => listener(true));
});

test('selecting a suggested question keeps the chat open without a server method call', async () => {
    let focused = 0;
    const scope = {
        chatOpen: true,
        $wire: new Proxy({ question: '' }, {
            get(target, key) {
                assert.equal(key, 'question', 'Selection must not call a Livewire server method');
                return target[key];
            },
        }),
        $event: { target: { value: 'Trang này dùng để làm gì?' } },
        $nextTick: (callback) => callback(),
        document: { getElementById: () => ({ focus() { focused++; } }) },
    };
    await evaluate(attribute(conversation, 'x-on:change'), scope);
    assert.equal(scope.$wire.question, 'Trang này dùng để làm gì?');
    assert.equal(scope.$event.target.value, '');
    assert.equal(scope.chatOpen, true);
    assert.equal(focused, 1);

    const launcher = widget.match(/<button type="button" x-ref="launcher"[\s\S]*?<\/button>/)?.[0];
    assert.ok(launcher);
    const toggle = attribute(launcher, 'x-on:click');
    await evaluate(toggle, scope);
    assert.equal(scope.chatOpen, false);
    await evaluate(toggle, scope);
    assert.equal(scope.chatOpen, true);
    assert.equal(focused, 2);
});
