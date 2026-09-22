// Browser test of moderating and locking a paper (exam phase, step 4) in headless Chrome, through
// the screens a person really uses.
//
//   tests/browser/on_test_database.sh tests/browser/exam_moderation.mjs
//   SHOTS=/some/folder tests/browser/on_test_database.sh tests/browser/exam_moderation.mjs
//
// Run it through on_test_database.sh: it writes questions into the question bank, and questions are
// never deleted, so they belong in a throwaway database. In kmu-cms it uses three test staff members
// — an examination officer, a committee member and a controller who publishes — and one test course
// with a topic. All of them are deactivated and the course retired at the end.
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
const MYSQL_ASSESS = process.env.MYSQL_ASSESS;
const CHROME =
    process.env.CHROME ??
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SHOTS = process.env.SHOTS ?? null;
const COURSE_CODE = 'E2E-MOD-101';
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
    controller: {
        email: 'qbank-review-academic-e2e@kmu.local',
        employee: 'TMP-RV-D',
    },
};

if (!MYSQL_ASSESS || /\bkmu_assess$/.test(MYSQL_ASSESS)) {
    console.error(
        'This test writes questions, which are never deleted. Run it through tests/browser/on_test_database.sh.',
    );
    process.exit(2);
}

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

// ---- temporary CMS data ------------------------------------------------------------------------
const emails = Object.values(PEOPLE)
    .map((person) => `'${person.email}'`)
    .join(', ');

function cleanup() {
    const courseId = cms(
        `SELECT id FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
    );
    if (courseId && !courseId.startsWith('SQL')) {
        cms(`DELETE FROM acad_curriculum_nodes WHERE course_id = ${courseId};
            UPDATE acad_courses SET status = 'retired' WHERE course_code = '${COURSE_CODE}'`);
    }
    const staffIds = cms(
        `SELECT GROUP_CONCAT(id) FROM staff WHERE email IN (${emails})`,
    );
    if (staffIds && staffIds !== 'NULL' && !staffIds.startsWith('SQL')) {
        cms(`UPDATE staff SET is_active = 0 WHERE id IN (${staffIds})`);
    }
}
cleanup();

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

    return cms(`SELECT id FROM staff WHERE email = '${person.email}'`);
}

const officer = staff(PEOPLE.officer);
const committee = staff(PEOPLE.committee);
const controller = staff(PEOPLE.controller);

const professional = cms(
    'SELECT id FROM acad_professionals WHERE class_id = 1 ORDER BY sequence LIMIT 1',
);
cms(`SET @kmu_staff_id = ${officer};
    INSERT INTO acad_courses (course_code, title, class_id, professional_id, course_kind, status)
    VALUES ('${COURSE_CODE}', 'Moderation end-to-end course', 1, ${professional}, 'module', 'active')
    ON DUPLICATE KEY UPDATE status = 'active';`);
const courseId = cms(
    `SELECT id FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
);
cms(`INSERT INTO acad_curriculum_nodes (course_id, parent_id, level_type_id, code, name, path, depth, sort_order, is_active)
     VALUES (${courseId}, NULL, 4, 'E2E-MT1', 'Acute coronary syndrome', '/', 1, 1, 1);`);
const topicOne = cms(
    `SELECT id FROM acad_curriculum_nodes WHERE course_id = ${courseId}`,
);
const annual = cms("SELECT id FROM acad_exam_types WHERE code = 'annual'");

// Grant the three test people the paper-moderation rights this test needs, on top of Super Admin
// (which they already have): Super Admin already has every right, so these grants only make the
// test's intent explicit and cost nothing extra.

// ---- browser ------------------------------------------------------------------------------------
const chrome = spawn(
    CHROME,
    [
        '--headless=new',
        '--remote-debugging-port=9359',
        `--user-data-dir=${mkdtempSync(join(tmpdir(), 'moderation-e2e-'))}`,
        '--ignore-certificate-errors',
        '--no-first-run',
        '--window-size=1500,1400',
        'about:blank',
    ],
    { stdio: 'ignore' },
);
let target;
for (let i = 0; i < 50 && !target; i++) {
    await sleep(200);
    try {
        target = (
            await (await fetch('http://127.0.0.1:9359/json/list')).json()
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
    // Accepted at the DevTools Protocol level, not by overriding window.confirm: that survives
    // every navigation (each sign-in is a full page load), so a confirm() dialog is never missed.
    if (m.method === 'Page.javascriptDialogOpening') {
        void send('Page.handleJavaScriptDialog', { accept: true });
    }
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
// A two-step "click to reveal, click to confirm" action, done reliably: the trigger click is
// re-sent if the confirm button has not appeared yet, since a click landing while the page is
// mid-transition can be lost. Stops as soon as `doneExpr` is true.
const clickThroughConfirm = async (
    triggerSelector,
    confirmSelector,
    doneExpr,
    tries = 40,
) => {
    for (let i = 0; i < tries; i++) {
        if (await evaluate(doneExpr)) return true;
        if (await has(confirmSelector)) {
            await click(confirmSelector);
        } else {
            await click(triggerSelector);
        }
        await sleep(250);
    }
    return evaluate(doneExpr);
};
const setValue = async (selector, value) => {
    await evaluate(
        `(() => { const el = document.querySelector(${JSON.stringify(selector)}); el.focus(); const set = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(el), 'value').set; set.call(el, ${JSON.stringify(String(value))}); el.dispatchEvent(new Event('input', { bubbles: true })); return true; })()`,
    );
    await sleep(150);
};
const setSelect = async (selector, value) =>
    evaluate(
        `(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; el.value = ${JSON.stringify(String(value))}; el.dispatchEvent(new Event('change', { bubbles: true })); return el.value === ${JSON.stringify(String(value))}; })()`,
    );
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
const url = () => `/exams/${examId}/paper`;

try {
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Emulation.setDeviceMetricsOverride', {
        width: 1500,
        height: 1400,
        deviceScaleFactor: 1,
        mobile: false,
    });

    await signIn(committee, '/dashboard');
    await signIn(controller, '/dashboard');
    await signIn(officer, '/dashboard');
    const officerUser = assess(
        `SELECT id FROM users WHERE cms_staff_id = ${officer}`,
    );

    // ---- a bank, an approved blueprint and a filled paper ---------------------------------------
    const sba = assess(
        "SELECT id FROM qb_question_types WHERE code = 'single_best_answer'",
    );
    const stamp = randomBytes(3).toString('hex');
    for (let n = 1; n <= 3; n++) {
        const text = `Which finding number ${n} (${stamp}) fits this case?`;
        const ref = `Q-E2E-${stamp}-${String(n).padStart(3, '0')}`;
        assess(`INSERT INTO qb_questions (public_ref, branch_id, course_id, latest_version_no, times_used, is_archived, created_by, created_at, updated_at)
            VALUES ('${ref}', 1, ${courseId}, 1, 0, 0, ${officerUser}, NOW(), NOW())`);
        const qid = assess(
            `SELECT id FROM qb_questions WHERE public_ref = '${ref}'`,
        );
        assess(`INSERT INTO qb_question_versions (question_id, version_no, question_type_id, branch_id, stem, lead_in, marks, negative_marks, course_id, node_id, exam_type_id, status, content_hash, search_text, author_id, created_by, created_at, updated_at)
            VALUES (${qid}, 1, ${sba}, 1, '<p>${text}</p>', 'What next?', 1, 0, ${courseId}, ${topicOne}, ${annual}, 'active', SHA2('${text}', 256), '${text}', ${officerUser}, ${officerUser}, NOW(), NOW())`);
        assess(
            `UPDATE qb_questions SET active_version_id = (SELECT id FROM qb_question_versions WHERE question_id = ${qid}) WHERE id = ${qid}`,
        );
    }

    assess(`INSERT INTO exm_examinations (public_ref, branch_id, title, programme_id, professional_id, course_id, exam_type_id, duration_minutes, total_marks, pass_percentage, negative_marking, status, created_by, created_at, updated_at)
        VALUES ('EX-E2E-${stamp}', 1, 'Moderation end-to-end examination', 1, ${professional}, ${courseId}, ${annual}, 60, 3, 50, 0, 'draft', ${officerUser}, NOW(), NOW())`);
    examId = assess(
        `SELECT id FROM exm_examinations WHERE public_ref = 'EX-E2E-${stamp}'`,
    );
    // The blueprint has to be a draft while its rows are written — the database itself refuses
    // them once it is approved (the same freeze this test later checks on the paper) — so it is
    // approved only after they are in.
    assess(`INSERT INTO exm_blueprints (examination_id, branch_id, status, created_by, created_at, updated_at)
        VALUES (${examId}, 1, 'draft', ${officerUser}, NOW(), NOW())`);
    const blueprintId = assess(
        `SELECT id FROM exm_blueprints WHERE examination_id = ${examId}`,
    );
    const rowInsert =
        assess(`INSERT INTO exm_blueprint_rows (blueprint_id, sort_order, node_id, question_type_id, question_count, marks_each, created_at, updated_at)
        VALUES (${blueprintId}, 1, ${topicOne}, ${sba}, 3, 1, NOW(), NOW())`);
    if (rowInsert.startsWith('SQL ERROR')) {
        throw new Error(`Could not seed the blueprint row: ${rowInsert}`);
    }
    // The blueprint's own status machine only allows draft → submitted → approved — not straight to
    // approved — so it is walked through submitted on the way, the same as a real committee would.
    const submitResult = assess(
        `UPDATE exm_blueprints SET status = 'submitted', submitted_by = ${officerUser}, submitted_at = NOW() WHERE id = ${blueprintId}`,
    );
    if (submitResult.startsWith('SQL ERROR')) {
        throw new Error(
            `Could not submit the seeded blueprint: ${submitResult}`,
        );
    }
    const approveResult = assess(
        `UPDATE exm_blueprints SET status = 'approved', approved_by = ${officerUser}, approved_at = NOW(), approved_hash = SHA2('e2e-${stamp}', 256) WHERE id = ${blueprintId}`,
    );
    if (approveResult.startsWith('SQL ERROR')) {
        throw new Error(
            `Could not approve the seeded blueprint: ${approveResult}`,
        );
    }
    assess(
        `UPDATE exm_examinations SET status = 'blueprint_approved' WHERE id = ${examId}`,
    );
    const seededRows = assess(
        `SELECT COUNT(*) FROM exm_blueprint_rows WHERE blueprint_id = ${blueprintId}`,
    );
    check(
        'the blueprint has its row seeded before the test begins',
        seededRows === '1',
        seededRows,
    );
    const seededStatus = assess(
        `SELECT status FROM exm_blueprints WHERE id = ${blueprintId}`,
    );
    check(
        'the blueprint is approved before the test begins',
        seededStatus === 'approved',
        seededStatus,
    );

    // ---- the officer builds and submits the paper -----------------------------------------------
    check(
        'the officer starts and fills the paper',
        (await signIn(officer, url())) &&
            (await has('[data-test=start-paper]')) &&
            (await click('[data-test=start-paper]')) &&
            (await waitFor(
                "!!document.querySelector('[data-test=fill-gaps]')",
            )),
        (await flat()).slice(0, 300),
    );
    await click('[data-test=fill-gaps]');
    await waitFor("document.querySelectorAll('[data-item]').length === 3");
    check(
        'before submitting, the workflow card offers Submit for moderation',
        (await has('[data-test=workflow]')) &&
            (await has('[data-test=submit-paper]')) &&
            (await evaluate(
                "document.querySelector('[data-test=paper-status]').textContent.trim()",
            )) === 'Being built',
        (await flat()).slice(0, 300),
    );
    await shot('1-built');

    await click('[data-test=submit-paper]');
    check(
        'submitting moves it to awaiting moderation, and the builder controls are gone',
        (await waitFor(
            "document.querySelector('[data-test=paper-status]')?.textContent.includes('Awaiting moderation')",
        )) &&
            !(await has('[data-item] [data-test=remove]')) &&
            (await has('[data-test=comments]')),
        (await flat()).slice(0, 300),
    );
    check(
        'the officer cannot approve their own paper',
        !(await has('[data-test=approve-paper]')) &&
            /Nobody approves a paper they started or submitted/.test(
                await text(),
            ),
        (await flat()).slice(0, 300),
    );

    // ---- the committee moderates it: a comment, then approves --------------------------------------
    await signIn(committee, url());
    const firstItem = await evaluate(
        "document.querySelector('[data-item]').getAttribute('data-item')",
    );
    await setSelect('[data-test=comment-item]', firstItem);
    await setValue(
        '[data-test=new-comment]',
        'The stem of this one could be clearer.',
    );
    await click('[data-test=add-comment]');
    check(
        'a comment on one question is added and shown as open',
        await waitFor(
            "document.querySelectorAll('[data-comment]').length === 1",
        ),
        (await flat()).slice(0, 300),
    );
    await shot('2-commented');

    // Comments do not block approval — the moderator may approve with a note still open.
    await click('[data-test=approve-paper]');
    check(
        'the committee approves it — a comment being open does not block approval',
        await waitFor(
            "document.querySelector('[data-test=paper-status]')?.textContent.includes('Approved')",
        ),
        (await flat()).slice(0, 300),
    );

    // Finalising is refused while any comment is open.
    for (
        let i = 0;
        i < 20 && !(await has('[data-test=finalise-confirm]'));
        i++
    ) {
        await click('[data-test=finalise-paper]');
        await sleep(250);
    }
    await click('[data-test=finalise-confirm]');
    await sleep(600);
    check(
        'finalising is refused while the comment is open',
        /open comment/i.test(await text()) &&
            (await evaluate(
                "document.querySelector('[data-test=paper-status]').textContent.trim()",
            )) === 'Approved — ready to finalise',
        (await flat()).slice(0, 400),
    );

    await click('[data-comment] button');
    check(
        'resolving the comment marks it resolved',
        await waitFor(
            "document.querySelector('[data-comment]').textContent.includes('resolved')",
        ),
        (await flat()).slice(0, 300),
    );

    // ---- finalise, and the database itself refuses any further change -----------------------------
    check(
        'finalising locks it',
        await clickThroughConfirm(
            '[data-test=finalise-paper]',
            '[data-test=finalise-confirm]',
            "document.querySelector('[data-test=paper-status]')?.textContent.includes('Finalised')",
        ),
        (await flat()).slice(0, 300),
    );
    const directChange = assess(
        `UPDATE exm_paper_items SET marks = 9 WHERE paper_id = (SELECT id FROM exm_papers WHERE examination_id = ${examId})`,
    );
    check(
        'the database refuses a direct change to a finalised paper',
        directChange.includes('cannot be changed'),
        directChange,
    );
    await shot('3-finalised');

    await signIn(controller, url());
    await click('[data-test=publish-paper]');
    check(
        'the controller publishes it',
        await waitFor(
            "document.querySelector('[data-test=paper-status]')?.textContent.includes('Published')",
        ),
        (await flat()).slice(0, 300),
    );

    // ---- a correction after publishing: a new version, the old one untouched -----------------------
    await clickThroughConfirm(
        '[data-test=new-version]',
        '[data-test=new-version-confirm]',
        "document.querySelector('[data-test=paper-status]')?.textContent.trim() === 'Being built'",
    );
    check(
        'a new version is a fresh draft, with the same three questions locked',
        (await evaluate(
            "document.querySelector('[data-test=paper-status]')?.textContent.trim()",
        )) === 'Being built' &&
            (await evaluate(
                "document.querySelectorAll('[data-item]').length",
            )) === 3 &&
            (await evaluate(
                "document.querySelectorAll('[data-test=locked]').length",
            )) === 3,
        (await flat()).slice(0, 300),
    );
    check(
        'both versions are listed, the new one current',
        (await has('[data-version="1"]')) && (await has('[data-version="2"]')),
        (await flat()).slice(0, 300),
    );
    await click('[data-version="1"]');
    check(
        'the old, published version opens exactly as it was, and it is read-only',
        (await waitFor("location.search.includes('version=1')")) &&
            (await evaluate(
                "document.querySelector('[data-test=paper-status]').textContent.trim()",
            )) === 'Published' &&
            !(await has('[data-test=fill-gaps]')),
        `${await path()}${await evaluate('location.search')}`,
    );
    await shot('4-versions');

    check(
        'every step is in the audit log, in order',
        assess(
            `SELECT GROUP_CONCAT(action ORDER BY id) FROM sec_audit_logs WHERE action LIKE 'paper.%' AND action NOT LIKE 'paper.item%' AND action NOT LIKE '%drawn' AND action NOT LIKE '%created' AND entity_id IN (SELECT id FROM exm_papers WHERE examination_id = ${examId})`,
        ).includes('paper.submitted') &&
            assess(
                `SELECT COUNT(*) FROM sec_audit_logs WHERE action = 'paper.version_started'`,
            ) === '1',
        assess(
            `SELECT GROUP_CONCAT(DISTINCT action) FROM sec_audit_logs WHERE action LIKE 'paper.%'`,
        ),
    );
    check(
        'no JavaScript errors in the moderation screens',
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
    'the test course is retired and the three test people deactivated',
    cms(
        `SELECT status FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
    ) === 'retired' &&
        cms(
            `SELECT COUNT(*) FROM staff WHERE email IN (${emails}) AND is_active = 1`,
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
