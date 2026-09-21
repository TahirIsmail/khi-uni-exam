// Browser test of setting an examination up and writing its blueprint (exam phase, steps 1 and 2)
// in headless Chrome, through the screens a person really uses.
//
//   node tests/browser/exam_blueprint.mjs https://kmu-assess.test
//   SHOTS=/some/folder node tests/browser/exam_blueprint.mjs   (also saves a screenshot of each screen)
//
// Local development only. It uses two test staff members in the CMS — an examination officer and a
// committee member, the same two people as the review test — and one test course with a heading and
// two topics. The examination ends as a draft, so it can be deleted again; the people are
// deactivated and the course retired at the end, because courses and staff who did something are
// never deleted. Audit entries always stay.
import { execSync, spawn } from 'node:child_process';
import { createHmac, randomBytes } from 'node:crypto';
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
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
const SHOTS = process.env.SHOTS ?? null;
const COURSE_CODE = 'E2E-EXM-101';
const SUPER_ADMIN_ROLE = 7;
const PEOPLE = {
    officer: {
        email: 'qbank-review-author-e2e@kmu.local',
        employee: 'TMP-RV-A',
    },
    committee: {
        email: 'qbank-review-approver-e2e@kmu.local',
        employee: 'TMP-RV-P',
    },
};

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

// ---- temporary CMS and module data -----------------------------------------------------------
const emails = Object.values(PEOPLE)
    .map((person) => `'${person.email}'`)
    .join(', ');

function cleanup() {
    const courseId = cms(
        `SELECT id FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
    );
    if (courseId && !courseId.startsWith('SQL')) {
        // A draft examination can be deleted; its blueprint, rows and sections go with it.
        assess(
            `DELETE FROM exm_examinations WHERE course_id = ${courseId} AND status = 'draft'`,
        );
        cms(`DELETE FROM acad_curriculum_nodes WHERE course_id = ${courseId} AND depth = 2;
            DELETE FROM acad_curriculum_nodes WHERE course_id = ${courseId};
            UPDATE acad_courses SET status = 'retired' WHERE course_code = '${COURSE_CODE}'`);
    }

    const staffIds = cms(
        `SELECT GROUP_CONCAT(id) FROM staff WHERE email IN (${emails})`,
    );
    if (staffIds && staffIds !== 'NULL' && !staffIds.startsWith('SQL')) {
        const userIds = assess(
            `SELECT GROUP_CONCAT(id) FROM users WHERE cms_staff_id IN (${staffIds})`,
        );
        if (userIds && userIds !== 'NULL') {
            assess(`DELETE FROM sessions WHERE user_id IN (${userIds})`);
        }
        assess(
            `DELETE FROM sso_consumed_tickets WHERE cms_staff_id IN (${staffIds})`,
        );
        cms(`UPDATE staff SET is_active = 0 WHERE id IN (${staffIds})`);
    }
}
cleanup();

/** The same two test people every run, each with the Super Admin role so they may do everything. */
function staff(person) {
    if (
        cms(`SELECT COUNT(*) FROM staff WHERE email = '${person.email}'`) ===
        '0'
    ) {
        const template = cms(
            `SELECT MIN(staff_id) FROM staff_roles WHERE role_id = ${SUPER_ADMIN_ROLE}`,
        );
        const cols = cms(
            "SELECT GROUP_CONCAT(CONCAT('`', column_name, '`') ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'staff' AND column_name NOT IN ('id','email','employee_id','name','surname','branch_id')",
        );
        cms(`SET SESSION sql_mode = '';
            INSERT INTO staff (${cols}, email, employee_id, name, surname, branch_id) SELECT ${cols}, '${person.email}', '${person.employee}', 'Tmp', '${person.employee}', 1 FROM staff WHERE id = ${template};
            INSERT INTO staff_roles (role_id, staff_id, is_active) VALUES (${SUPER_ADMIN_ROLE}, LAST_INSERT_ID(), 1);`);
    } else {
        cms(`UPDATE staff SET is_active = 1 WHERE email = '${person.email}'`);
    }

    const id = cms(`SELECT id FROM staff WHERE email = '${person.email}'`);
    assess(
        `UPDATE users SET cms_staff_id = ${id}, is_active = 1 WHERE email = '${person.email}'`,
    );

    return id;
}

const officer = staff(PEOPLE.officer);
const committee = staff(PEOPLE.committee);

const professional = cms(
    'SELECT id FROM acad_professionals WHERE class_id = 1 ORDER BY sequence LIMIT 1',
);
cms(`SET @kmu_staff_id = ${officer};
    INSERT INTO acad_courses (course_code, title, class_id, professional_id, course_kind, status)
    VALUES ('${COURSE_CODE}', 'Examination end-to-end course', 1, ${professional}, 'module', 'active')
    ON DUPLICATE KEY UPDATE status = 'active';`);
const courseId = cms(
    `SELECT id FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
);
cms(`INSERT INTO acad_curriculum_nodes (course_id, parent_id, level_type_id, code, name, path, depth, sort_order, is_active)
     VALUES (${courseId}, NULL, 3, 'E2E-XH', 'Cardiology', '/', 1, 1, 1);`);
const heading = cms(
    `SELECT id FROM acad_curriculum_nodes WHERE course_id = ${courseId} AND depth = 1`,
);
cms(`INSERT INTO acad_curriculum_nodes (course_id, parent_id, level_type_id, code, name, path, depth, sort_order, is_active)
     VALUES (${courseId}, ${heading}, 4, 'E2E-XT1', 'Acute coronary syndrome', '/${heading}/', 2, 1, 1),
            (${courseId}, ${heading}, 4, 'E2E-XT2', 'Heart failure', '/${heading}/', 2, 2, 1);`);
const topicOne = cms(
    `SELECT id FROM acad_curriculum_nodes WHERE course_id = ${courseId} AND code = 'E2E-XT1'`,
);
const topicTwo = cms(
    `SELECT id FROM acad_curriculum_nodes WHERE course_id = ${courseId} AND code = 'E2E-XT2'`,
);
const annual = cms("SELECT id FROM acad_exam_types WHERE code = 'annual'");

// ---- browser ----------------------------------------------------------------------------------
const chrome = spawn(
    CHROME,
    [
        '--headless=new',
        '--remote-debugging-port=9357',
        `--user-data-dir=${mkdtempSync(join(tmpdir(), 'exam-e2e-'))}`,
        '--ignore-certificate-errors',
        '--no-first-run',
        '--window-size=1500,1300',
        'about:blank',
    ],
    { stdio: 'ignore' },
);
let target;
for (let i = 0; i < 50 && !target; i++) {
    await sleep(200);
    try {
        target = (
            await (await fetch('http://127.0.0.1:9357/json/list')).json()
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
const flat = async () => (await text()).replace(/\s+/g, ' ');
const has = async (selector) =>
    evaluate(`!!document.querySelector(${JSON.stringify(selector)})`);
const click = async (selector) =>
    evaluate(
        `(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; el.click(); return true; })()`,
    );
const setSelect = async (selector, value) =>
    evaluate(
        `(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; el.value = ${JSON.stringify(String(value))}; el.dispatchEvent(new Event('change', { bubbles: true })); return el.value === ${JSON.stringify(String(value))}; })()`,
    );
/** Types into a field the way a keyboard would, replacing what was there. */
const setValue = async (selector, value) => {
    await evaluate(
        `(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; el.focus(); el.select && el.select(); return true; })()`,
    );
    await evaluate(
        `(() => { const el = document.querySelector(${JSON.stringify(selector)}); const set = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(el), 'value').set; set.call(el, ${JSON.stringify(String(value))}); el.dispatchEvent(new Event('input', { bubbles: true })); return true; })()`,
    );
    await sleep(120);
};
/** Waits until a database question answers as expected: the screen and the database settle at different moments. */
const until = async (query, expected, tries = 40) => {
    for (let i = 0; i < tries; i++) {
        if (assess(query) === expected) return true;
        await sleep(250);
    }
    return false;
};
const shot = async (name) => {
    if (SHOTS === null) return;
    mkdirSync(SHOTS, { recursive: true });
    await sleep(300);
    const { result } = await send('Page.captureScreenshot', {
        format: 'png',
        captureBeyondViewport: true,
    });
    writeFileSync(
        join(SHOTS, `${name}.png`),
        Buffer.from(result.data, 'base64'),
    );
};
/** Signs in as one of the people, landing on the given page. */
const signIn = async (staffId, redirect) => {
    await send('Page.navigate', { url: 'about:blank' });
    await evaluate(
        `(() => { const f = document.createElement('form'); f.method = 'post'; f.action = '${ASSESS}/sso/cms'; const i = document.createElement('input'); i.name = 'ticket'; i.value = ${JSON.stringify(ticket(staffId, redirect))}; f.appendChild(i); document.body.appendChild(f); f.submit(); return true; })()`,
    );
    return (
        (await waitFor(
            `location.pathname === ${JSON.stringify(redirect.split('?')[0])}`,
        )) && (await waitFor('document.body.innerText.length > 100'))
    );
};

let examId = '0';
const examUrl = () => `/exams/${examId}`;

try {
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Emulation.setDeviceMetricsOverride', {
        width: 1500,
        height: 1300,
        deviceScaleFactor: 1,
        mobile: false,
    });

    // A member of staff becomes a user of the module the first time they open it from the CMS.
    await signIn(committee, '/dashboard');

    // ---- the officer opens the examinations page ---------------------------------------------
    check(
        "the officer opens Create exam from the module's menu",
        (await signIn(officer, '/dashboard')) &&
            (await evaluate(
                "!![...document.querySelectorAll('a')].find((a) => a.textContent.trim() === 'Create exam')",
            )),
        (await flat()).slice(0, 300),
    );
    await click('a[href$="/exams"]');
    check(
        'the examinations page opens, with the stages and a way to add one',
        (await waitFor("location.pathname === '/exams'")) &&
            (await waitFor(
                "!!document.querySelector('[data-test=new-exam]')",
            )) &&
            /Blueprint/.test(await text()),
        await path(),
    );
    await shot('1-examinations');

    // ---- 1. the examination -------------------------------------------------------------------
    await click('[data-test=new-exam]');
    check(
        'a new examination asks for the programme, year, examination and course, in that order',
        (await waitFor("!!document.querySelector('[data-test=exam-form]')")) &&
            (await has('[data-test=programme]')) &&
            (await has('[data-test=year]')) &&
            (await has('[data-test=exam-type]')) &&
            (await has('[data-test=course]')) &&
            /Create.*Examination|Examination.*Blueprint.*Paper/s.test(
                await text(),
            ),
        await path(),
    );

    // The form opens on the first programme; the test course is MBBS's, in its first professional year.
    await setSelect('[data-test=programme]', 1);
    await sleep(200);
    await setSelect('[data-test=year]', professional);
    await sleep(300);

    const options = await evaluate(
        "[...document.querySelectorAll('[data-test=course] option')].map((o) => o.textContent.trim())",
    );
    check(
        'the course list offers the Course IDs of the chosen year, with their codes',
        options.some((label) => label.startsWith(`${COURSE_CODE} —`)),
        JSON.stringify(options),
    );
    const examTypes = await evaluate(
        "[...document.querySelectorAll('[data-test=exam-type] option')].map((o) => o.textContent.trim())",
    );
    check(
        'MBBS offers Annual and Supplementary, not Regular and Retake',
        examTypes.includes('Annual') &&
            examTypes.includes('Supplementary') &&
            !examTypes.includes('Regular'),
        JSON.stringify(examTypes),
    );

    await setSelect('[data-test=course]', courseId);
    await setSelect('[data-test=exam-type]', annual);
    await sleep(300);
    const title = await evaluate(
        "document.querySelector('[data-test=title]').value",
    );
    check(
        'the title writes itself from the choices',
        /MBBS/.test(title) &&
            /Annual Examination/.test(title) &&
            title.includes(COURSE_CODE),
        title,
    );

    await setValue('[data-test=starts-at]', '2026-10-05T09:00');
    await setValue('[data-test=duration]', '120');
    await setValue('[data-test=total-marks]', '20');
    await setValue('[data-test=pass-percentage]', '50');
    check(
        'the pass mark says what it comes to in marks',
        /10 of 20 marks/.test(await text()),
        (await flat()).slice(0, 400),
    );
    await click('[data-test=negative-marking]');
    check(
        'negative marking asks how much a wrong answer loses',
        await waitFor(
            "!!document.querySelector('[data-test=negative-fraction]')",
        ),
    );
    await setValue('[data-test=negative-fraction]', '0.25');
    await setValue('[data-test=instructions]', 'Answer every question.');
    await shot('2-new-examination');

    await click('[data-test=save-exam]');
    await sleep(2500);
    await shot('2b-after-save');
    check(
        'saving takes the officer straight to planning the blueprint',
        await waitFor(
            '/^\\/exams\\/\\d+\\/blueprint$/.test(location.pathname)',
        ),
        `${await path()} ${await evaluate("[...document.querySelectorAll('.text-destructive')].map((e) => e.textContent.trim()).join(' | ')")}`,
    );
    examId = (await path()).split('/')[2];

    const stored = assess(
        `SELECT CONCAT(course_id, '|', exam_type_id, '|', duration_minutes, '|', total_marks, '|', pass_percentage, '|', negative_marking, '|', negative_fraction, '|', DATE_FORMAT(starts_at, '%Y-%m-%d %H:%i'), '|', status) FROM exm_examinations WHERE id = ${examId}`,
    );
    check(
        'the examination is stored as entered — the time in UTC, five hours before Karachi',
        stored ===
            `${courseId}|${annual}|120|20.00|50.00|1|0.250|2026-10-05 04:00|draft`,
        stored,
    );
    check(
        'it starts with an empty blueprint in preparation, and the audit log has it',
        assess(
            `SELECT status FROM exm_blueprints WHERE examination_id = ${examId}`,
        ) === 'draft' &&
            assess(
                `SELECT COUNT(*) FROM sec_audit_logs WHERE action = 'exam.created' AND entity_id = '${examId}'`,
            ) === '1',
    );

    // ---- 2. the blueprint --------------------------------------------------------------------
    check(
        'the blueprint page shows where the examination is, and what is missing',
        (await has('[data-test=exam-journey] [data-step=current]')) &&
            (await has('[data-test=blockers]')) &&
            /Add at least one row/.test(await text()),
        (await flat()).slice(0, 400),
    );

    await click('[data-test=add-row]');
    await sleep(200);
    await setSelect('[data-row="0"] [data-test=row-topic]', topicOne);
    await setValue('[data-row="0"] [data-test=row-count]', '10');
    await setValue('[data-row="0"] [data-test=row-marks]', '1');
    check(
        'the totals follow what is typed, and say how far the rows are from the total marks',
        (await evaluate(
            "document.querySelector('[data-test=planned]').textContent.replace(/\\s+/g, ' ').trim()",
        )) === '10 of 20' && /add 10/.test(await text()),
        (await flat()).slice(0, 500),
    );
    check(
        'each row says how many questions the bank holds for it, and flags a shortage',
        (await evaluate(
            'document.querySelector(\'[data-row="0"] [data-test=row-available]\').textContent.trim()',
        )) === '0' && (await has('[data-test=shortages]')),
        (await flat()).slice(0, 500),
    );
    check(
        'the Save button waits for a complete blueprint; submitting waits for a saved one',
        (await evaluate(
            "document.querySelector('[data-test=submit-blueprint]')?.disabled === true",
        )) === true,
    );

    await click('[data-test=add-section]');
    await setValue('[data-section="0"]', 'Section A — Single best answer');
    await click('[data-test=add-row]');
    await sleep(200);
    await setSelect('[data-row="1"] [data-test=row-topic]', topicTwo);
    await setValue('[data-row="1"] [data-test=row-count]', '5');
    await setValue('[data-row="1"] [data-test=row-marks]', '2');
    await setSelect('[data-row="0"] [data-test=row-section]', '0');
    await setSelect('[data-row="1"] [data-test=row-section]', '0');

    check(
        'the rows now add up to the total marks',
        (await evaluate(
            "document.querySelector('[data-test=planned]').textContent.replace(/\\s+/g, ' ').trim()",
        )) === '20 of 20' &&
            (await has('[data-test=sound]')) &&
            (await evaluate(
                "document.querySelector('[data-test=total-questions]').textContent.trim()",
            )) === '15',
        (await flat()).slice(0, 500),
    );

    // A mix that does not add up is caught while it is typed.
    await setValue('[data-mix="difficulty-1"]', '40');
    await setValue('[data-mix="difficulty-2"]', '30');
    check(
        'a difficulty mix of 70% is not accepted',
        /adds up to 70%/.test(await text()) &&
            (await has('[data-test=blockers]')),
        (await flat()).slice(0, 500),
    );
    await setValue('[data-mix="difficulty-3"]', '30');
    await setValue('[data-mix="cognitive-1"]', '50');
    await setValue('[data-mix="cognitive-2"]', '50');
    check(
        'mixes that add up to 100% are accepted',
        (await has('[data-test=sound]')) &&
            /Adds up to 100%/.test(await text()),
        (await flat()).slice(0, 500),
    );
    await shot('3-blueprint');

    await click('[data-test=save-blueprint]');
    check(
        'saving stores the sections, the rows and the mixes',
        (await until(
            `SELECT CONCAT(COUNT(*), '|', SUM(question_count), '|', SUM(question_count * marks_each)) FROM exm_blueprint_rows WHERE blueprint_id = (SELECT id FROM exm_blueprints WHERE examination_id = ${examId})`,
            '2|15|20.00',
        )) &&
            assess(
                `SELECT COUNT(*) FROM exm_sections WHERE examination_id = ${examId}`,
            ) === '1' &&
            assess(
                `SELECT COUNT(*) FROM exm_blueprint_targets WHERE blueprint_id = (SELECT id FROM exm_blueprints WHERE examination_id = ${examId})`,
            ) === '5',
        assess(`SELECT COUNT(*) FROM exm_blueprint_rows`),
    );

    // Leaving and coming back finds it as it was left.
    await signIn(officer, `${examUrl()}/blueprint`);
    check(
        'the saved blueprint opens as it was left',
        (await evaluate(
            'document.querySelector(\'[data-row="1"] [data-test=row-count]\')?.value',
        )) === '5' &&
            (await evaluate(
                'document.querySelector(\'[data-section="0"]\')?.value',
            )) === 'Section A — Single best answer' &&
            (await evaluate(
                'document.querySelector(\'[data-mix="cognitive-1"]\')?.value',
            )) === '50',
        (await flat()).slice(0, 300),
    );

    // ---- submit -------------------------------------------------------------------------------
    await click('[data-test=submit-blueprint]');
    check(
        'submitting lands on the examination, awaiting approval',
        (await waitFor(`location.pathname === ${JSON.stringify(examUrl())}`)) &&
            (await waitFor(
                "document.querySelector('[data-test=blueprint-status]')?.textContent.includes('Awaiting approval')",
            )),
        await path(),
    );
    check(
        'the officer cannot approve their own blueprint, and is told why',
        !(await has('[data-test=approve-blueprint]')) &&
            /nobody approves a blueprint they wrote or submitted/i.test(
                await text(),
            ),
        (await flat()).slice(0, 400),
    );
    await shot('4-awaiting-approval');

    await signIn(officer, `${examUrl()}/blueprint`);
    check(
        'a submitted blueprint is read-only',
        (await has('[data-test=read-only]')) &&
            (await evaluate(
                'document.querySelector(\'[data-row="0"] [data-test=row-count]\')?.disabled',
            )) === true &&
            !(await has('[data-test=add-row]')),
        (await flat()).slice(0, 300),
    );

    // ---- the committee ------------------------------------------------------------------------
    check(
        'the committee member finds it awaiting approval on the list',
        (await signIn(committee, '/exams?stage=submitted')) &&
            (await waitFor(
                `!!document.querySelector('[data-exam="${examId}"]')`,
            )),
        (await flat()).slice(0, 300),
    );

    await signIn(committee, examUrl());
    check(
        'the committee sees Approve and Send back',
        (await has('[data-test=approve-blueprint]')) &&
            (await has('[data-test=send-back]')),
        (await flat()).slice(0, 300),
    );

    await click('[data-test=send-back]');
    await waitFor("!!document.querySelector('[data-test=send-back-reason]')");
    await click('[data-test=send-back-confirm]');
    await sleep(800);
    check(
        'sending it back needs a reason',
        /reason field is required|at least 10 characters/i.test(await text()) &&
            assess(
                `SELECT status FROM exm_blueprints WHERE examination_id = ${examId}`,
            ) === 'submitted',
        (await flat()).slice(0, 300),
    );
    await setValue(
        '[data-test=send-back-reason]',
        'Move five marks from recall to application.',
    );
    await click('[data-test=send-back-confirm]');
    check(
        'with a reason it goes back to the officer',
        (await waitFor(
            "document.querySelector('[data-test=blueprint-status]')?.textContent.includes('in preparation')",
        )) &&
            assess(
                `SELECT return_reason FROM exm_blueprints WHERE examination_id = ${examId}`,
            ).includes('recall to application'),
        (await flat()).slice(0, 300),
    );

    await signIn(officer, examUrl());
    check(
        'the officer reads why it came back',
        (await has('[data-test=returned]')) &&
            /recall to application/.test(await text()),
        (await flat()).slice(0, 300),
    );
    await shot('5-sent-back');

    await signIn(officer, `${examUrl()}/blueprint`);
    await click('[data-test=submit-blueprint]');
    check(
        'the officer submits it again',
        await waitFor(
            "document.querySelector('[data-test=blueprint-status]')?.textContent.includes('Awaiting approval')",
        ),
        await path(),
    );

    await signIn(committee, examUrl());
    await click('[data-test=approve-blueprint]');
    check(
        'the committee approves it',
        await waitFor(
            "document.querySelector('[data-test=blueprint-status]')?.textContent.includes('Ready for the paper')",
        ),
        (await flat()).slice(0, 300),
    );
    check(
        'the approval is fingerprinted and the examination is ready for its paper',
        assess(
            `SELECT LENGTH(approved_hash) FROM exm_blueprints WHERE examination_id = ${examId}`,
        ) === '64' &&
            assess(
                `SELECT status FROM exm_examinations WHERE id = ${examId}`,
            ) === 'blueprint_approved' &&
            (await has('[data-test=approved-by]')) &&
            (await has('[data-test=paper-next]')),
        (await flat()).slice(0, 400),
    );
    await shot('6-approved');

    check(
        'the database refuses to change an approved blueprint, even in SQL',
        assess(
            `UPDATE exm_blueprint_rows SET question_count = 1 WHERE blueprint_id = (SELECT id FROM exm_blueprints WHERE examination_id = ${examId})`,
        ).includes('submitted or approved') &&
            assess(
                `UPDATE exm_examinations SET total_marks = 30 WHERE id = ${examId}`,
            ).includes('cannot be changed'),
    );

    // ---- reopen -------------------------------------------------------------------------------
    await click('[data-test=reopen]');
    await waitFor("!!document.querySelector('[data-test=reopen-reason]')");
    await setValue(
        '[data-test=reopen-reason]',
        'The paper is to be split differently.',
    );
    await click('[data-test=reopen-confirm]');
    check(
        'an approved blueprint can be reopened with a reason, and goes back to preparation',
        (await waitFor(
            "document.querySelector('[data-test=blueprint-status]')?.textContent.includes('in preparation')",
        )) &&
            assess(
                `SELECT status FROM exm_examinations WHERE id = ${examId}`,
            ) === 'draft',
        (await flat()).slice(0, 300),
    );

    check(
        'every step is in the audit log, in order',
        assess(
            `SELECT GROUP_CONCAT(action ORDER BY id) FROM sec_audit_logs WHERE action LIKE 'blueprint.%' AND entity_id = (SELECT id FROM exm_blueprints WHERE examination_id = ${examId})`,
        ) ===
            'blueprint.saved,blueprint.submitted,blueprint.returned,blueprint.submitted,blueprint.approved,blueprint.reopened',
        assess(
            `SELECT GROUP_CONCAT(action ORDER BY id) FROM sec_audit_logs WHERE action LIKE 'blueprint.%' AND entity_id = (SELECT id FROM exm_blueprints WHERE examination_id = ${examId})`,
        ),
    );

    // ---- the list and the details -------------------------------------------------------------
    await signIn(officer, '/exams');
    check(
        'the list shows the examination with its planned questions and marks',
        (await has(`[data-exam="${examId}"]`)) &&
            /15 questions planned, 20 marks/.test(await text()),
        (await flat()).slice(0, 400),
    );
    await shot('7-list');

    await signIn(officer, `${examUrl()}/edit`);
    check(
        'the details come back as they were entered, in the examination time zone',
        (await evaluate(
            "document.querySelector('[data-test=starts-at]')?.value",
        )) === '2026-10-05T09:00' &&
            (await evaluate(
                "document.querySelector('[data-test=duration]')?.value",
            )) === '120' &&
            (await evaluate(
                "document.querySelector('[data-test=negative-fraction]')?.value",
            )) === '0.25',
        (await flat()).slice(0, 300),
    );

    check(
        'no JavaScript errors in the examination screens',
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
    'the test examination is deleted, the course retired and the two test people deactivated',
    assess(
        `SELECT COUNT(*) FROM exm_examinations WHERE course_id = ${courseId}`,
    ) === '0' &&
        cms(
            `SELECT status FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
        ) === 'retired' &&
        cms(
            `SELECT COUNT(*) FROM staff WHERE email IN (${emails}) AND is_active = 1`,
        ) === '0',
    assess(`SELECT COUNT(*) FROM exm_examinations`),
);

for (const r of results)
    console.log(
        `${r.ok ? 'PASS' : 'FAIL'}  ${r.name}${r.detail && !r.ok ? `  (${String(r.detail).slice(0, 300)})` : ''}`,
    );
console.log(
    `\npassed: ${results.filter((r) => r.ok).length}, failed: ${results.filter((r) => !r.ok).length}`,
);
process.exit(results.every((r) => r.ok) ? 0 : 1);
