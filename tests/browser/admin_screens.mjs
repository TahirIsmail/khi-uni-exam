// Browser check of the administration screens (Step 7b) in headless Chrome.
//
//   node tests/browser/admin_screens.mjs https://kmu-assess.test
//
// Local development only, and read-only on purpose: audit entries can never be deleted, so nothing
// is saved. It signs in as the CMS staff member CMS_STAFF_ID (default 1, a Super Admin) with a
// ticket signed by the local KMU_CMS_SSO_SECRET, opens each screen, and fails on any JavaScript
// error. A temporary CMS staff record (MYSQL_CMS) is created to open a scopes page, then removed.
import { execSync, spawn } from 'node:child_process';
import { createHmac, randomBytes } from 'node:crypto';
import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const ASSESS = (process.argv[2] ?? 'https://kmu-assess.test').replace(
    /\/$/,
    '',
);
const MYSQL_CMS = process.env.MYSQL_CMS ?? 'mysql -uroot kmu-cms';
const MYSQL_ASSESS = process.env.MYSQL_ASSESS ?? 'mysql -uroot kmu_assess';
const CMS_STAFF_ID = Number(process.env.CMS_STAFF_ID ?? 1);
const CHROME =
    process.env.CHROME ??
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const TEMP_EMAIL = 'admin-screens-e2e@kmu.local';

const run = (mysql, query) => {
    try {
        return execSync(`${mysql} -N`, {
            encoding: 'utf8',
            input: query,
            stdio: ['pipe', 'pipe', 'pipe'],
        }).trim();
    } catch (error) {
        return `SQL ERROR: ${String(error.stderr).trim()}`;
    }
};
const cms = (q) => run(MYSQL_CMS, q);
const assess = (q) => run(MYSQL_ASSESS, q);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const results = [];
const check = (name, ok, detail = '') =>
    results.push({ name, ok: Boolean(ok), detail });

const env = readFileSync(new URL('../../.env', import.meta.url), 'utf8');
const secret = Buffer.from(
    (env.match(/^KMU_CMS_SSO_SECRET=(.*)$/m) ?? [])[1]
        ?.trim()
        .replace(/^"|"$/g, '') ?? '',
    'base64',
);
const b64url = (buf) =>
    Buffer.from(buf)
        .toString('base64')
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
function ticket(redirect) {
    const now = Math.floor(Date.now() / 1000);
    const payload = b64url(
        JSON.stringify({
            iss: 'kmu-cms',
            aud: 'kmu-assess',
            sub: CMS_STAFF_ID,
            iat: now,
            exp: now + 60,
            jti: randomBytes(32).toString('hex'),
            redirect,
        }),
    );
    return `${payload}.${b64url(createHmac('sha256', secret).update(payload).digest())}`;
}

function cleanup() {
    const id = cms(`SELECT id FROM staff WHERE email = '${TEMP_EMAIL}'`);
    if (id && !id.startsWith('SQL')) {
        if (
            assess(`SELECT COUNT(*) FROM users WHERE cms_staff_id = ${id}`) !==
            '0'
        ) {
            console.error(
                'A local user was created for the temporary staff member; this check must stay read-only.',
            );
        }
        cms(
            `DELETE FROM staff WHERE id = ${id}; ALTER TABLE staff AUTO_INCREMENT = 1`,
        );
    }
}

cleanup();
const cols = cms(
    "SELECT GROUP_CONCAT(CONCAT('`', column_name, '`') ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'staff' AND column_name NOT IN ('id','email','employee_id','name','surname','branch_id')",
);
cms(
    `SET SESSION sql_mode = ''; INSERT INTO staff (${cols}, email, employee_id, name, surname, branch_id) SELECT ${cols}, '${TEMP_EMAIL}', 'TMP-ADMIN-E2E', 'Temp', 'Faculty', 1 FROM staff WHERE id = ${CMS_STAFF_ID};`,
);
const tempStaffId = cms(`SELECT id FROM staff WHERE email = '${TEMP_EMAIL}'`);

const chrome = spawn(
    CHROME,
    [
        '--headless=new',
        '--remote-debugging-port=9351',
        `--user-data-dir=${mkdtempSync(join(tmpdir(), 'admin-e2e-'))}`,
        '--ignore-certificate-errors',
        '--no-first-run',
        '--window-size=1400,1000',
        'about:blank',
    ],
    { stdio: 'ignore' },
);
let target;
for (let i = 0; i < 50 && !target; i++) {
    await sleep(200);
    try {
        target = (
            await (await fetch('http://127.0.0.1:9351/json/list')).json()
        ).find((t) => t.type === 'page');
    } catch {}
}
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((r) => ws.addEventListener('open', r));
let nextId = 0;
const pending = new Map();
const jsErrors = [];
ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) {
        pending.get(m.id)(m);
        pending.delete(m.id);
    }
    if (m.method === 'Runtime.exceptionThrown')
        jsErrors.push(
            m.params.exceptionDetails?.exception?.description ??
                m.params.exceptionDetails?.text,
        );
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error')
        jsErrors.push(
            m.params.args.map((a) => a.value ?? a.description).join(' '),
        );
    if (m.method === 'Log.entryAdded' && m.params.entry.level === 'error')
        jsErrors.push(`${m.params.entry.text} ${m.params.entry.url ?? ''}`);
});
const send = (method, params = {}) =>
    new Promise((r) => {
        const id = ++nextId;
        pending.set(id, r);
        ws.send(JSON.stringify({ id, method, params }));
    });
const evaluate = async (expression) =>
    (
        await send('Runtime.evaluate', {
            expression,
            awaitPromise: true,
            returnByValue: true,
        })
    ).result?.result?.value;
const waitFor = async (expression, tries = 60) => {
    for (let i = 0; i < tries; i++) {
        if (await evaluate(expression)) return true;
        await sleep(250);
    }
    return false;
};
const load = async (path) => {
    await send('Page.navigate', { url: ASSESS + path });
    await waitFor(
        "document.readyState === 'complete' && !!document.querySelector('main, [data-slot=sidebar-inset], #app > *')",
    );
    await sleep(600);
};
const text = async () => (await evaluate('document.body.innerText')) ?? '';

try {
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Log.enable');

    await send('Page.navigate', { url: 'about:blank' });
    await evaluate(
        `(() => { const f = document.createElement('form'); f.method = 'post'; f.action = '${ASSESS}/sso/cms'; const i = document.createElement('input'); i.name = 'ticket'; i.value = ${JSON.stringify(ticket('/admin/roles'))}; f.appendChild(i); document.body.appendChild(f); f.submit(); return true; })()`,
    );
    const landed = await waitFor(
        `location.pathname === '/admin/roles' && document.body.innerText.includes('Roles & permissions')`,
        60,
    );
    check(
        'signing in from kmu-cms can land on the roles screen',
        landed,
        await evaluate('location.href'),
    );

    const sidebar = await text();
    check(
        'sidebar shows the Administration links',
        /Administration/.test(sidebar) &&
            /Staff scopes/.test(sidebar) &&
            /Audit log/.test(sidebar),
    );
    check(
        'starter-kit links to external sites are gone',
        !(await evaluate(
            '!!document.querySelector(\'a[href*="github.com"], a[href*="laravel.com/docs"]\')',
        )),
    );

    const roleButtons = await evaluate(
        "[...document.querySelectorAll('ul[aria-label=Roles] button')].map(b => b.innerText)",
    );
    check(
        'roles from kmu-cms are listed, Super Admin first',
        Array.isArray(roleButtons) &&
            /Super Admin/.test(roleButtons[0]) &&
            roleButtons.some((r) => /Faculty/.test(r)),
        JSON.stringify(roleButtons?.slice(0, 3)),
    );
    check(
        'every catalogue permission has a checkbox',
        (await evaluate(
            "document.querySelectorAll('[data-permission]').length",
        )) === 50,
    );

    await evaluate(
        "[...document.querySelectorAll('ul[aria-label=Roles] button')].find(b => /Faculty/.test(b.innerText)).click()",
    );
    await sleep(400);
    const facultyEditable = await evaluate(
        "!document.querySelector('[data-permission=\"qbank.question.create\"]').disabled && !!document.querySelector('[data-test=save-role-permissions]')",
    );
    check(
        'a normal role is editable for a Super Admin (Save shown, checkboxes enabled)',
        facultyEditable,
    );
    await evaluate(
        'document.querySelector(\'[data-permission="qbank.question.create"]\').click()',
    );
    await sleep(300);
    check(
        'ticking a permission enables Save (not submitted)',
        await evaluate(
            "!document.querySelector('[data-test=save-role-permissions]').disabled",
        ),
    );
    await evaluate(
        "[...document.querySelectorAll('ul[aria-label=Roles] button')].find(b => /Super Admin/.test(b.innerText)).click()",
    );
    await sleep(400);
    check(
        'the Super Admin role is read-only',
        await evaluate(
            "!document.querySelector('[data-test=save-role-permissions]') && document.querySelector('[data-permission=\"exam.publish\"]').disabled",
        ),
    );

    await load('/admin/staff');
    const staffText = await text();
    check(
        'staff list shows kmu-cms staff of the campus',
        /Temp Faculty/.test(staffText) && /TMP-ADMIN-E2E/.test(staffText),
        staffText.slice(0, 200),
    );
    await evaluate("document.querySelector('input[type=search]').focus()");
    await send('Input.insertText', { text: 'no-such-person-xyz' });
    await send('Input.dispatchKeyEvent', {
        type: 'keyDown',
        key: 'Enter',
        code: 'Enter',
        windowsVirtualKeyCode: 13,
        text: '\r',
    });
    await send('Input.dispatchKeyEvent', {
        type: 'keyUp',
        key: 'Enter',
        code: 'Enter',
        windowsVirtualKeyCode: 13,
    });
    check(
        'search with no match says so',
        await waitFor("document.body.innerText.includes('No staff found')"),
        `${await evaluate('location.href')} ${(await text()).slice(0, 400)}`,
    );

    await load(`/admin/staff/${tempStaffId}`);
    const scopeText = await text();
    check(
        'scopes page for a staff member who has not signed in',
        /Temp Faculty/.test(scopeText) &&
            /Main Campus/.test(scopeText) &&
            /No scopes/.test(scopeText),
        scopeText.slice(0, 300),
    );
    await evaluate(
        "(() => { const s = document.getElementById('scope_type'); s.value = 'programme'; s.dispatchEvent(new Event('change')); return true; })()",
    );
    await sleep(300);
    const programmeOptions = await evaluate(
        "[...document.querySelectorAll('#scope_id option')].map(o => o.textContent.trim()).filter(t => t !== 'Choose…')",
    );
    check(
        'programme choices come from kmu-cms (MBBS, BDS, DPT)',
        ['MBBS', 'BDS', 'DPT'].every((p) =>
            programmeOptions?.some((o) => o.startsWith(p)),
        ),
        JSON.stringify(programmeOptions),
    );
    check(
        'opening the page did not create a local user',
        assess(
            `SELECT COUNT(*) FROM users WHERE cms_staff_id = ${tempStaffId}`,
        ) === '0',
    );

    await load(`/admin/staff/${CMS_STAFF_ID}`);
    check(
        'your own scopes page is refused',
        /403|forbidden/i.test(await text()),
    );

    await load('/admin/audit');
    check(
        'audit log lists the sign-in',
        /identity\.sso\.login/.test(await text()),
    );
    await evaluate(
        "[...document.querySelectorAll('button')].find(b => b.innerText === 'Details').click()",
    );
    check(
        'an entry expands to show its details',
        await waitFor("document.body.innerText.includes('IP address')"),
    );
    await evaluate(
        "document.querySelector('[data-test=verify-chain]').click()",
    );
    check(
        'chain check reports the log intact',
        await waitFor(
            '/Intact: all \\d+ entries/.test(document.body.innerText)',
        ),
        (await text()).slice(0, 200),
    );
    const exportHref = await evaluate(
        "document.querySelector('[data-test=export-audit]')?.getAttribute('href')",
    );
    check(
        'export link points to the CSV export',
        exportHref === '/admin/audit/export',
        exportHref,
    );

    await load("/admin/audit?action=x'%20OR%201=1");
    const afterBad = await evaluate('location.pathname + location.search');
    check(
        'a malformed filter is rejected before any query (redirected back without it)',
        afterBad === '/admin/audit',
        afterBad,
    );

    check(
        'no JavaScript or CSP errors on any screen',
        jsErrors.filter((e) => !/403|Forbidden/i.test(e)).length === 0,
        jsErrors.join(' | '),
    );
} finally {
    ws.close();
    chrome.kill();
    cleanup();
}

check(
    'temporary CMS staff record removed',
    cms(`SELECT COUNT(*) FROM staff WHERE email = '${TEMP_EMAIL}'`) === '0',
);

for (const r of results)
    console.log(
        `${r.ok ? 'PASS' : 'FAIL'}  ${r.name}${r.detail && !r.ok ? `  (${String(r.detail).slice(0, 300)})` : ''}`,
    );
console.log(
    `\npassed: ${results.filter((r) => r.ok).length}, failed: ${results.filter((r) => !r.ok).length}`,
);
process.exit(results.every((r) => r.ok) ? 0 : 1);
