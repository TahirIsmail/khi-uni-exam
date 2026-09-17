// Browser test of the question editor (Step 9) in headless Chrome.
//
//   node tests/browser/question_editor.mjs https://kmu-assess.test
//
// Local development only. It reuses one test staff member (Super Admin) and one test course with two
// curriculum nodes in the CMS database: both are activated at the start and deactivated/retired at
// the end, because courses are never deleted and a staff member who wrote questions cannot be
// removed either. The curriculum nodes, sessions and tickets are removed; a question that was sent
// for review stays in the bank, archived. Audit entries always stay.
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
const CHROME =
    process.env.CHROME ??
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const EMAIL = 'qbank-editor-e2e@kmu.local';
const COURSE_CODE = 'E2E-QB-101';
const SUPER_ADMIN_ROLE = 7;

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
function ticket(staffId, redirect) {
    const now = Math.floor(Date.now() / 1000);
    const payload = b64url(
        JSON.stringify({
            iss: 'kmu-cms',
            aud: 'kmu-assess',
            sub: Number(staffId),
            iat: now,
            exp: now + 60,
            jti: randomBytes(32).toString('hex'),
            redirect,
            branch: 1,
        }),
    );
    return `${payload}.${b64url(createHmac('sha256', secret).update(payload).digest())}`;
}

// ---- temporary CMS data ----------------------------------------------------------------------
function cleanup() {
    const staffId = cms(`SELECT id FROM staff WHERE email = '${EMAIL}'`);
    const courseId = cms(
        `SELECT id FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
    );

    if (courseId && !courseId.startsWith('SQL')) {
        const questionIds = assess(
            `SELECT GROUP_CONCAT(id) FROM qb_questions WHERE course_id = ${courseId}`,
        );
        if (questionIds && questionIds !== 'NULL') {
            // Drafts are deleted; anything already sent for review stays, archived.
            assess(`UPDATE qb_questions SET active_version_id = NULL WHERE id IN (${questionIds});
                DELETE FROM qb_question_versions WHERE question_id IN (${questionIds}) AND status = 'draft';
                UPDATE qb_questions SET is_archived = 1, archive_reason = 'question editor end-to-end test' WHERE id IN (${questionIds})`);
        }
        cms(`DELETE FROM acad_curriculum_nodes WHERE course_id = ${courseId} AND depth = 2;
            DELETE FROM acad_curriculum_nodes WHERE course_id = ${courseId};
            UPDATE acad_courses SET status = 'retired' WHERE id = ${courseId}`);
    }

    if (staffId && !staffId.startsWith('SQL')) {
        const userId = assess(
            `SELECT id FROM users WHERE cms_staff_id = ${staffId}`,
        );
        if (userId) assess(`DELETE FROM sessions WHERE user_id = ${userId}`);
        assess(
            `DELETE FROM sso_consumed_tickets WHERE cms_staff_id = ${staffId}`,
        );
        cms(`UPDATE staff SET is_active = 0 WHERE id = ${staffId}`);
    }
}

cleanup();
// The same test author every run: a staff member who has written questions cannot be deleted.
if (cms(`SELECT COUNT(*) FROM staff WHERE email = '${EMAIL}'`) === '0') {
    const template = cms(
        `SELECT MIN(staff_id) FROM staff_roles WHERE role_id = ${SUPER_ADMIN_ROLE}`,
    );
    const cols = cms(
        "SELECT GROUP_CONCAT(CONCAT('`', column_name, '`') ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'staff' AND column_name NOT IN ('id','email','employee_id','name','surname','branch_id')",
    );
    cms(`SET SESSION sql_mode = '';
        INSERT INTO staff (${cols}, email, employee_id, name, surname, branch_id) SELECT ${cols}, '${EMAIL}', 'TMP-QB-EDITOR', 'Tmp', 'Author', 1 FROM staff WHERE id = ${template};
        INSERT INTO staff_roles (role_id, staff_id, is_active) VALUES (${SUPER_ADMIN_ROLE}, LAST_INSERT_ID(), 1);`);
} else {
    cms(`UPDATE staff SET is_active = 1 WHERE email = '${EMAIL}'`);
}
const staffId = cms(`SELECT id FROM staff WHERE email = '${EMAIL}'`);
// A module user left by an earlier run points at the staff row of that run; point it at this one.
assess(
    `UPDATE users SET cms_staff_id = ${staffId}, is_active = 1 WHERE email = '${EMAIL}'`,
);

const professional = cms(
    'SELECT id FROM acad_professionals WHERE class_id = 1 ORDER BY sequence LIMIT 1',
);
// The same test course every run: courses are never deleted, so it is re-activated and retired again.
if (
    cms(
        `SELECT COUNT(*) FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
    ) === '0'
) {
    cms(`SET @kmu_staff_id = ${staffId};
        INSERT INTO acad_courses (course_code, title, class_id, professional_id, course_kind, status)
        VALUES ('${COURSE_CODE}', 'Editor end-to-end course', 1, ${professional}, 'module', 'active');`);
} else {
    cms(
        `SET @kmu_staff_id = ${staffId}; UPDATE acad_courses SET status = 'active' WHERE course_code = '${COURSE_CODE}'`,
    );
}
const courseId = cms(
    `SELECT id FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
);
// Depth 1 is a discipline level (no questions), depth 2 is a topic (questions allowed) for MBBS.
cms(`INSERT INTO acad_curriculum_nodes (course_id, parent_id, level_type_id, code, name, path, depth, sort_order, is_active)
     VALUES (${courseId}, NULL, 3, 'E2E-D', 'Cardiology (discipline)', '/', 1, 1, 1);`);
const disciplineNode = cms(
    `SELECT id FROM acad_curriculum_nodes WHERE course_id = ${courseId} AND depth = 1`,
);
cms(`INSERT INTO acad_curriculum_nodes (course_id, parent_id, level_type_id, code, name, path, depth, sort_order, is_active)
     VALUES (${courseId}, ${disciplineNode}, 4, 'E2E-T', 'Acute coronary syndrome', '/${disciplineNode}/', 2, 1, 1);`);

// ---- browser --------------------------------------------------------------------------------
const chrome = spawn(
    CHROME,
    [
        '--headless=new',
        '--remote-debugging-port=9353',
        `--user-data-dir=${mkdtempSync(join(tmpdir(), 'qb-editor-e2e-'))}`,
        '--ignore-certificate-errors',
        '--no-first-run',
        '--window-size=1500,1100',
        'about:blank',
    ],
    { stdio: 'ignore' },
);
let target;
for (let i = 0; i < 50 && !target; i++) {
    await sleep(200);
    try {
        target = (
            await (await fetch('http://127.0.0.1:9353/json/list')).json()
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
const waitFor = async (expression, tries = 80) => {
    for (let i = 0; i < tries; i++) {
        if (await evaluate(expression)) return true;
        await sleep(250);
    }
    return false;
};
const path = async () => evaluate('location.pathname');
const text = async () =>
    (await evaluate('document.body ? document.body.innerText : ""')) ?? '';
const load = async (p) => {
    await send('Page.navigate', { url: ASSESS + p });
    await waitFor("document.readyState === 'complete'");
    await sleep(800);
};
const click = async (selector) =>
    evaluate(
        `(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; el.click(); return true; })()`,
    );
const type = async (selector, value) => {
    await evaluate(
        `document.querySelector(${JSON.stringify(selector)}).focus()`,
    );
    await send('Input.insertText', { text: value });
    await sleep(150);
};
const setSelect = async (selector, value) =>
    evaluate(
        `(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; el.value = ${JSON.stringify(String(value))}; el.dispatchEvent(new Event('change', { bubbles: true })); return el.value === ${JSON.stringify(String(value))}; })()`,
    );

try {
    await send('Page.enable');
    await send('Runtime.enable');

    await send('Page.navigate', { url: 'about:blank' });
    await evaluate(
        `(() => { const f = document.createElement('form'); f.method = 'post'; f.action = '${ASSESS}/sso/cms'; const i = document.createElement('input'); i.name = 'ticket'; i.value = ${JSON.stringify(ticket(staffId, '/questions/create'))}; f.appendChild(i); document.body.appendChild(f); f.submit(); return true; })()`,
    );
    check(
        'the editor opens from kmu-cms',
        await waitFor(
            "location.pathname === '/questions/create' && !!document.getElementById('type')",
        ),
        await path(),
    );

    // The way in: the sidebar link and the dashboard.
    await load('/dashboard');
    const home = await text();
    check(
        'the sidebar offers the question bank',
        /Question bank/.test(home) && /Back to CMS/.test(home),
        home.slice(0, 200),
    );
    check(
        'the dashboard counts the campus questions',
        /Questions in this campus/.test(home) &&
            /Where the questions stand/.test(home),
        home.slice(0, 300),
    );
    await evaluate(
        "[...document.querySelectorAll('a')].find(a => a.textContent.trim() === 'Question bank')?.click()",
    );
    check(
        'the sidebar link opens the question bank',
        await waitFor("location.pathname === '/questions'"),
        await path(),
    );
    await load('/questions/create');
    await waitFor("!!document.getElementById('type')");

    check(
        'the course from the CMS is offered',
        await setSelect('#course', courseId),
    );
    await sleep(1200);
    const topics = await evaluate(
        "[...document.querySelectorAll('#topic option')].map(o => o.textContent.trim())",
    );
    check(
        'only topics that take questions are offered (the discipline level is not)',
        Array.isArray(topics) &&
            topics.some((t) => t.includes('Acute coronary syndrome')) &&
            !topics.some((t) => t.includes('discipline')),
        JSON.stringify(topics),
    );

    const topicId = cms(
        `SELECT id FROM acad_curriculum_nodes WHERE course_id = ${courseId} AND depth = 2`,
    );
    await setSelect('#topic', topicId);
    await type(
        '#stem',
        'A 54-year-old man has crushing chest pain radiating to the jaw for 40 minutes and ST elevation in leads II, III and aVF.',
    );
    await type(
        '#lead-in',
        'Which is the most appropriate immediate treatment?',
    );

    for (const option of [
        'Primary percutaneous coronary intervention',
        'Oral beta blocker',
        'Chest radiograph',
        'Discharge with antacids',
    ]) {
        await click('[data-test=add-option]');
        await sleep(200);
        const index =
            (await evaluate(
                "document.querySelectorAll('[data-option]').length",
            )) - 1;
        await type(`[data-option]:nth-of-type(${index + 1}) textarea`, option);
    }
    check(
        'four options were added',
        (await evaluate(
            "document.querySelectorAll('[data-option]').length",
        )) === 4,
    );

    // Live checks are debounced, so wait for the panel to catch up.
    const missingKey = await waitFor(
        "/correct option/i.test(document.querySelector('[data-test=checks-errors]')?.innerText ?? '')",
    );
    check(
        'the checks say the key is missing',
        missingKey,
        await evaluate(
            "document.querySelector('[data-test=checks-errors]')?.innerText ?? ''",
        ),
    );

    await click('[data-correct=A]');
    await sleep(1200);
    check(
        'marking the key clears the errors',
        await waitFor("!!document.querySelector('[data-test=checks-ready]')"),
        await evaluate(
            "document.querySelector('[data-test=checks-errors]')?.innerText ?? ''",
        ),
    );

    const preview = await evaluate(
        "document.querySelector('[data-test=candidate-preview]')?.innerText ?? ''",
    );
    check(
        'the preview shows the question as a candidate sees it, with the key marked',
        /crushing chest pain/.test(preview) && /key/.test(preview),
        preview.slice(0, 200),
    );

    await click('[data-test=add-reference]');
    await sleep(200);
    await type(
        '[aria-label="Reference 1"]',
        'Harrison, Principles of Internal Medicine, 21st ed',
    );

    await click('[data-test=save-draft]');
    check(
        'the draft is saved and gets a reference',
        await waitFor(
            "/Q-\\d{4}-\\d{6}/.test(document.body.innerText) && location.pathname.includes('/edit')",
        ),
        await path(),
    );
    const reference = (await text()).match(/Q-\d{4}-\d{6}/)?.[0];
    check(
        'the draft is in the bank as a draft',
        assess(
            `SELECT status FROM qb_question_versions v JOIN qb_questions q ON q.id = v.question_id WHERE q.public_ref = '${reference}'`,
        ) === 'draft',
        String(reference),
    );
    check(
        'the option texts and the key were stored',
        assess(
            `SELECT GROUP_CONCAT(CONCAT(label, IF(is_correct, '*', '')) ORDER BY sort_order) FROM qb_question_options o JOIN qb_question_versions v ON v.id = o.version_id JOIN qb_questions q ON q.id = v.question_id WHERE q.public_ref = '${reference}'`,
        ) === 'A*,B,C,D',
    );

    // Changing the type reshapes the editor without losing the text.
    const mtf =
        cms('SELECT 0') &&
        (await evaluate(
            "[...document.querySelectorAll('#type option')].find(o => o.textContent.includes('Multiple true'))?.value",
        ));
    await setSelect('#type', mtf);
    await sleep(900);
    check(
        'changing the type shows the statements editor instead of options',
        (await evaluate(
            "!!document.querySelector('[data-test=add-item]') && !document.querySelector('[data-test=add-option]')",
        )) === true,
    );
    check(
        'the question text is kept when the type changes',
        /crushing chest pain/.test(
            await evaluate("document.getElementById('stem').value"),
        ),
    );

    await load(
        `/questions/${assess(`SELECT q.id FROM qb_questions q WHERE q.public_ref = '${reference}'`)}/versions/${assess(`SELECT v.id FROM qb_question_versions v JOIN qb_questions q ON q.id = v.question_id WHERE q.public_ref = '${reference}'`)}/edit`,
    );
    await waitFor("!!document.querySelector('[data-test=submit-question]')");
    check(
        'the saved draft opens again with its options',
        (await evaluate(
            "document.querySelectorAll('[data-option]').length",
        )) === 4,
    );

    await click('[data-test=submit-question]');
    check(
        'sending for review lands on the question list',
        await waitFor("location.pathname === '/questions'"),
        await path(),
    );
    check(
        'the question is now submitted',
        assess(
            `SELECT status FROM qb_question_versions v JOIN qb_questions q ON q.id = v.question_id WHERE q.public_ref = '${reference}'`,
        ) === 'submitted',
    );
    check('the list shows it as submitted', /Submitted/.test(await text()));

    const questionId = assess(
        `SELECT id FROM qb_questions WHERE public_ref = '${reference}'`,
    );
    const versionId = assess(
        `SELECT v.id FROM qb_question_versions v WHERE v.question_id = ${questionId}`,
    );
    await load(`/questions/${questionId}/versions/${versionId}`);
    const shown = await text();
    check(
        'the submitted question is read-only, with its answer key and explanation area',
        /Submitted/.test(shown) &&
            /New version/.test(shown) &&
            !/Save draft/.test(shown),
        shown.slice(0, 200),
    );

    check(
        'no JavaScript errors in the editor',
        jsErrors.length === 0,
        jsErrors.join(' | '),
    );
} catch (error) {
    check(
        `the test run stopped unexpectedly at ${await path()}`,
        false,
        error.stack,
    );
} finally {
    ws.close();
    chrome.kill();
    cleanup();
}

check(
    'the test course is retired, the test author deactivated and the topics removed',
    cms(
        `SELECT status FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
    ) === 'retired' &&
        cms(`SELECT is_active FROM staff WHERE email = '${EMAIL}'`) === '0' &&
        cms(
            `SELECT COUNT(*) FROM acad_curriculum_nodes n JOIN acad_courses c ON c.id = n.course_id WHERE c.course_code = '${COURSE_CODE}'`,
        ) === '0',
);

for (const r of results)
    console.log(
        `${r.ok ? 'PASS' : 'FAIL'}  ${r.name}${r.detail && !r.ok ? `  (${String(r.detail).slice(0, 300)})` : ''}`,
    );
console.log(
    `\npassed: ${results.filter((r) => r.ok).length}, failed: ${results.filter((r) => !r.ok).length}`,
);
process.exit(results.every((r) => r.ok) ? 0 : 1);
