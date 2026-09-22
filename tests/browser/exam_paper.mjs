// Browser test of building a paper from the question bank (exam phase, step 3) in headless Chrome,
// through the screens a person really uses.
//
//   tests/browser/on_test_database.sh tests/browser/exam_paper.mjs
//   SHOTS=/some/folder tests/browser/on_test_database.sh tests/browser/exam_paper.mjs
//
// Run it through on_test_database.sh: it writes twenty questions into the question bank, and
// questions are never deleted, so they belong in a throwaway database. In kmu-cms it uses two test
// staff members (an examination officer and a committee member) and one test course with a heading
// and two topics; all of them are deactivated and the course retired at the end.
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
const COURSE_CODE = 'E2E-PAP-101';
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
        cms(`DELETE FROM acad_curriculum_nodes WHERE course_id = ${courseId} AND depth = 2;
            DELETE FROM acad_curriculum_nodes WHERE course_id = ${courseId};
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

const professional = cms(
    'SELECT id FROM acad_professionals WHERE class_id = 1 ORDER BY sequence LIMIT 1',
);
cms(`SET @kmu_staff_id = ${officer};
    INSERT INTO acad_courses (course_code, title, class_id, professional_id, course_kind, status)
    VALUES ('${COURSE_CODE}', 'Paper end-to-end course', 1, ${professional}, 'module', 'active')
    ON DUPLICATE KEY UPDATE status = 'active';`);
const courseId = cms(
    `SELECT id FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
);
cms(`INSERT INTO acad_curriculum_nodes (course_id, parent_id, level_type_id, code, name, path, depth, sort_order, is_active)
     VALUES (${courseId}, NULL, 3, 'E2E-PH', 'Cardiology', '/', 1, 1, 1);`);
const heading = cms(
    `SELECT id FROM acad_curriculum_nodes WHERE course_id = ${courseId} AND depth = 1`,
);
cms(`INSERT INTO acad_curriculum_nodes (course_id, parent_id, level_type_id, code, name, path, depth, sort_order, is_active)
     VALUES (${courseId}, ${heading}, 4, 'E2E-PT1', 'Acute coronary syndrome', '/${heading}/', 2, 1, 1),
            (${courseId}, ${heading}, 4, 'E2E-PT2', 'Heart failure', '/${heading}/', 2, 2, 1);`);
const topicOne = cms(
    `SELECT id FROM acad_curriculum_nodes WHERE course_id = ${courseId} AND code = 'E2E-PT1'`,
);
const topicTwo = cms(
    `SELECT id FROM acad_curriculum_nodes WHERE course_id = ${courseId} AND code = 'E2E-PT2'`,
);
const annual = cms("SELECT id FROM acad_exam_types WHERE code = 'annual'");

// ---- browser ------------------------------------------------------------------------------------
const chrome = spawn(
    CHROME,
    [
        '--headless=new',
        '--remote-debugging-port=9358',
        `--user-data-dir=${mkdtempSync(join(tmpdir(), 'paper-e2e-'))}`,
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
            await (await fetch('http://127.0.0.1:9358/json/list')).json()
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
const count = async (selector) =>
    evaluate(`document.querySelectorAll(${JSON.stringify(selector)}).length`);
const click = async (selector) =>
    evaluate(
        `(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; el.click(); return true; })()`,
    );
const setValue = async (selector, value) => {
    await evaluate(
        `(() => { const el = document.querySelector(${JSON.stringify(selector)}); el.focus(); const set = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(el), 'value').set; set.call(el, ${JSON.stringify(String(value))}); el.dispatchEvent(new Event('input', { bubbles: true })); return true; })()`,
    );
    await sleep(150);
};
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
/** The dialogs the page asks ("draw again?") are answered yes. */
const acceptDialogs = () =>
    evaluate('window.confirm = () => true; window.prompt = () => "yes"; true');

let examId = '0';
const examUrl = () => `/exams/${examId}`;
const rowsSummary = async () =>
    evaluate(
        "[...document.querySelectorAll('[data-test=row-progress]')].map((e) => e.textContent.trim()).join(' | ')",
    );

try {
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Emulation.setDeviceMetricsOverride', {
        width: 1500,
        height: 1400,
        deviceScaleFactor: 1,
        mobile: false,
    });

    // Both become users of the module the first time they open it.
    await signIn(committee, '/dashboard');
    await signIn(officer, '/dashboard');
    const officerUser = assess(
        `SELECT id FROM users WHERE cms_staff_id = ${officer}`,
    );

    // ---- an approved examination and a bank to draw on ------------------------------------------
    const sba = assess(
        "SELECT id FROM qb_question_types WHERE code = 'single_best_answer'",
    );
    const recall = assess(
        'SELECT id FROM qb_cognitive_levels WHERE is_active = 1 ORDER BY sort_order LIMIT 1',
    );
    const application = assess(
        'SELECT id FROM qb_cognitive_levels WHERE is_active = 1 ORDER BY sort_order LIMIT 1 OFFSET 2',
    );
    const stamp = randomBytes(3).toString('hex');

    const question = (n, node, cognitive, extra = {}) => {
        const text =
            extra.stem ??
            `Which finding number ${n} (${stamp}) fits this cardiology case?`;
        const ref = `Q-E2E-${stamp}-${String(n).padStart(3, '0')}`;
        assess(`INSERT INTO qb_questions (public_ref, branch_id, course_id, latest_version_no, times_used, last_used_at, is_archived, created_by, created_at, updated_at)
            VALUES ('${ref}', 1, ${courseId}, 1, ${extra.used ? 2 : 0}, ${extra.used ? 'NOW() - INTERVAL 1 MONTH' : 'NULL'}, 0, ${officerUser}, NOW(), NOW())`);
        const questionId = assess(
            `SELECT id FROM qb_questions WHERE public_ref = '${ref}'`,
        );
        assess(`INSERT INTO qb_question_versions (question_id, version_no, question_type_id, branch_id, stem, lead_in, marks, negative_marks, course_id, node_id, cognitive_level_id, exam_type_id, status, content_hash, search_text, author_id, created_by, created_at, updated_at)
            VALUES (${questionId}, 1, ${sba}, 1, '<p>${text}</p>', 'What is the most likely diagnosis?', 1, 0, ${courseId}, ${node}, ${cognitive}, ${annual}, 'active', SHA2('${text}', 256), '${text}', ${officerUser}, ${officerUser}, NOW(), NOW())`);
        assess(
            `UPDATE qb_questions SET active_version_id = (SELECT id FROM qb_question_versions WHERE question_id = ${questionId}) WHERE id = ${questionId}`,
        );
        return ref;
    };
    // Twelve for the first topic (half of them recall), six for the second; one used lately.
    for (let n = 1; n <= 12; n++)
        question(n, topicOne, n <= 6 ? recall : application);
    for (let n = 13; n <= 18; n++) question(n, topicTwo, application);
    question(19, topicTwo, application, { used: true });
    check(
        'the bank holds nineteen questions in use for the course',
        assess(
            `SELECT COUNT(*) FROM qb_questions q JOIN qb_question_versions v ON v.id = q.active_version_id WHERE q.course_id = ${courseId} AND v.status = 'active'`,
        ) === '19',
    );

    // 4 questions from the first topic and 3 from the second: 7 marks, 60% recall / 40% application.
    assess(`INSERT INTO exm_examinations (public_ref, branch_id, title, programme_id, professional_id, course_id, exam_type_id, duration_minutes, total_marks, pass_percentage, negative_marking, status, created_by, created_at, updated_at)
        VALUES ('EX-E2E-${stamp}', 1, 'Paper end-to-end examination', 1, ${professional}, ${courseId}, ${annual}, 90, 7, 50, 0, 'draft', ${officerUser}, NOW(), NOW())`);
    examId = assess(
        `SELECT id FROM exm_examinations WHERE public_ref = 'EX-E2E-${stamp}'`,
    );
    assess(
        `INSERT INTO exm_blueprints (examination_id, branch_id, status, created_by, created_at, updated_at) VALUES (${examId}, 1, 'draft', ${officerUser}, NOW(), NOW())`,
    );
    const blueprintId = assess(
        `SELECT id FROM exm_blueprints WHERE examination_id = ${examId}`,
    );
    assess(`INSERT INTO exm_blueprint_rows (blueprint_id, sort_order, node_id, question_type_id, question_count, marks_each, created_at, updated_at)
        VALUES (${blueprintId}, 1, ${topicOne}, ${sba}, 4, 1, NOW(), NOW()), (${blueprintId}, 2, ${topicTwo}, ${sba}, 3, 1, NOW(), NOW())`);
    assess(`INSERT INTO exm_blueprint_targets (blueprint_id, dimension, level_id, percent, created_at, updated_at)
        VALUES (${blueprintId}, 'cognitive', ${recall}, 60, NOW(), NOW()), (${blueprintId}, 'cognitive', ${application}, 40, NOW(), NOW())`);
    assess(
        `UPDATE exm_blueprints SET status = 'submitted', submitted_by = ${officerUser}, submitted_at = NOW() WHERE id = ${blueprintId}`,
    );
    assess(
        `UPDATE exm_blueprints SET status = 'approved', approved_by = ${officerUser}, approved_at = NOW(), approved_hash = SHA2('e2e-${stamp}', 256) WHERE id = ${blueprintId}`,
    );
    assess(
        `UPDATE exm_examinations SET status = 'blueprint_approved' WHERE id = ${examId}`,
    );

    // ---- the examination's page offers the paper -------------------------------------------------
    check(
        'the approved examination offers to build the paper',
        (await signIn(officer, examUrl())) &&
            (await waitFor(
                "!!document.querySelector('[data-test=open-paper]')",
            )) &&
            /Build the paper/.test(await text()),
        (await flat()).slice(0, 300),
    );
    await click('[data-test=open-paper]');
    check(
        'the paper page has not been started, and offers to start it',
        (await waitFor(
            "!!document.querySelector('[data-test=start-paper]')",
        )) && (await path()) === `${examUrl()}/paper`,
        await path(),
    );
    await shot('1-start');

    await click('[data-test=start-paper]');
    check(
        'starting it shows every row of the blueprint, empty',
        (await waitFor(
            "document.querySelectorAll('[data-row]').length === 2",
        )) &&
            (await rowsSummary()) === '0 of 4 | 0 of 3' &&
            assess(
                `SELECT COUNT(*) FROM exm_papers WHERE examination_id = ${examId}`,
            ) === '1',
        await rowsSummary(),
    );
    check(
        'each row says how many the bank could still give it',
        /4 more needed; 12 more in the question bank/.test(await text()) &&
            /3 more needed; 7 more in the question bank/.test(await text()),
        (await flat()).slice(0, 600),
    );

    // ---- fill from the bank -----------------------------------------------------------------
    await click('[data-test=fill-gaps]');
    check(
        'filling the gaps draws the seven questions the blueprint asks for',
        (await waitFor(
            "document.querySelectorAll('[data-item]').length === 7",
        )) &&
            (await rowsSummary()) === '4 of 4 | 3 of 3' &&
            (await has('[data-test=complete]')),
        await rowsSummary(),
    );
    const drawn = assess(
        `SELECT GROUP_CONCAT(CONCAT(i.row_node_id, ':', v.cognitive_level_id) ORDER BY i.position) FROM exm_paper_items i JOIN qb_question_versions v ON v.id = i.version_id WHERE i.paper_id = (SELECT id FROM exm_papers WHERE examination_id = ${examId})`,
    );
    const asked = drawn.split(',');
    const recalls = asked.filter((x) => x.endsWith(`:${recall}`)).length;
    check(
        'the draw keeps to the blueprint: topics, and 60% recall (4 of 7 questions)',
        asked.filter((x) => x.startsWith(`${topicOne}:`)).length === 4 &&
            asked.filter((x) => x.startsWith(`${topicTwo}:`)).length === 3 &&
            recalls === 4,
        drawn,
    );
    check(
        'the question drawn that was used lately is not chosen when others are free',
        assess(
            `SELECT COUNT(*) FROM exm_paper_items i JOIN qb_questions q ON q.id = i.question_id WHERE i.paper_id = (SELECT id FROM exm_papers WHERE examination_id = ${examId}) AND q.times_used > 0`,
        ) === '0',
    );
    check(
        'each question shows its text, its levels and how often it has been used',
        /Which finding number/.test(await text()) &&
            /Never used/.test(await text()),
        (await flat()).slice(0, 500),
    );
    check(
        'the mix table shows what was asked for and what the paper has',
        (await text()).includes('Cognitive level') && /60%/.test(await text()),
        (await flat()).slice(0, 300),
    );
    check(
        'the officer is warned that they wrote these questions themselves',
        await has('[data-warning=own]'),
        (await flat()).slice(0, 400),
    );
    await shot('2-drawn');

    // ---- lock, redraw ----------------------------------------------------------------------------
    const firstItem = await evaluate(
        "document.querySelector('[data-item]').getAttribute('data-item')",
    );
    await click(`[data-item="${firstItem}"] [data-test=lock]`);
    check(
        'a question can be locked',
        (await waitFor(
            `!!document.querySelector('[data-item="${firstItem}"] [data-test=locked]')`,
        )) &&
            (await until(
                `SELECT is_locked FROM exm_paper_items WHERE id = ${firstItem}`,
                '1',
            )),
    );
    const before = assess(
        `SELECT GROUP_CONCAT(id ORDER BY id) FROM exm_paper_items WHERE paper_id = (SELECT id FROM exm_papers WHERE examination_id = ${examId})`,
    );
    await acceptDialogs();
    await click('[data-test=redraw]');
    await sleep(1500);
    await waitFor("document.querySelectorAll('[data-item]').length === 7");
    const after = assess(
        `SELECT GROUP_CONCAT(id ORDER BY id) FROM exm_paper_items WHERE paper_id = (SELECT id FROM exm_papers WHERE examination_id = ${examId})`,
    );
    check(
        'drawing again keeps the locked question and replaces the others',
        after.split(',').includes(firstItem) &&
            after.split(',').length === 7 &&
            after.split(',').filter((id) => before.split(',').includes(id))
                .length === 1,
        `${before} -> ${after}`,
    );

    // ---- take one out, choose one by hand -------------------------------------------------------------
    const secondItem = await evaluate(
        "document.querySelectorAll('[data-item]')[1].getAttribute('data-item')",
    );
    check(
        'a locked question cannot be taken out or swapped',
        (await evaluate(
            `document.querySelector('[data-item="${firstItem}"] [data-test=remove]').disabled`,
        )) === true &&
            (await evaluate(
                `document.querySelector('[data-item="${firstItem}"] [data-test=swap]').disabled`,
            )) === true,
    );
    await click(`[data-item="${secondItem}"] [data-test=remove]`);
    check(
        'taking a question out leaves a gap the row shows',
        (await waitFor(
            "document.querySelectorAll('[data-item]').length === 6",
        )) && (await rowsSummary()) === '3 of 4 | 3 of 3',
        await rowsSummary(),
    );

    await click('[data-row] [data-test=add-question]');
    check(
        "the picker lists what the row could take, with each question's history",
        (await waitFor(
            "document.querySelectorAll('[data-candidate]').length > 0",
        )) && /never used/.test(await text()),
        (await flat()).slice(0, 300),
    );
    await setValue('[data-test=picker-search]', `number 7 (${stamp})`);
    check(
        'searching narrows the list to the words typed',
        await waitFor(
            "document.querySelectorAll('[data-candidate]').length === 1",
        ),
        String(await count('[data-candidate]')),
    );
    await shot('3-picker');
    const chosen = await evaluate(
        "document.querySelector('[data-candidate]').getAttribute('data-candidate')",
    );
    await click('[data-candidate] [data-test=choose]');
    check(
        'choosing it puts it in the row, marked as chosen by hand',
        (await waitFor(
            "document.querySelectorAll('[data-item]').length === 7",
        )) &&
            !(await has('[data-test=picker]')) &&
            (await until(
                `SELECT source FROM exm_paper_items WHERE question_id = ${chosen}`,
                'manual',
            )),
        await rowsSummary(),
    );

    // ---- swap -------------------------------------------------------------------------------------
    const target = await evaluate(
        "document.querySelector('[data-row] [data-item]:not(:has([data-test=locked])) [data-test=swap]').closest('[data-item]').getAttribute('data-item')",
    );
    const targetQuestion = assess(
        `SELECT question_id FROM exm_paper_items WHERE id = ${target}`,
    );
    await click(`[data-item="${target}"] [data-test=swap]`);
    await waitFor("document.querySelectorAll('[data-candidate]').length > 0");
    await click('[data-candidate] [data-test=choose]');
    check(
        'a question is swapped for another of the same row, keeping its place',
        (await until(
            `SELECT COUNT(*) FROM exm_paper_items WHERE id = ${target} AND question_id <> ${targetQuestion}`,
            '1',
        )) && (await rowsSummary()) === '4 of 4 | 3 of 3',
        await rowsSummary(),
    );

    // ---- how each candidate meets the paper ---------------------------------------------------------
    await click('[data-test=shuffle-questions]');
    check(
        'the order of questions can be fixed for everybody',
        await until(
            `SELECT shuffle_questions FROM exm_papers WHERE examination_id = ${examId}`,
            '0',
        ),
    );

    // ---- the blueprint reopens: the paper holds still ---------------------------------------------------
    await signIn(committee, `${examUrl()}/blueprint`);
    await signIn(committee, examUrl());
    await click('[data-test=reopen]');
    await waitFor("!!document.querySelector('[data-test=reopen-reason]')");
    await setValue(
        '[data-test=reopen-reason]',
        'The paper is to be split in two sections.',
    );
    await click('[data-test=reopen-confirm]');
    await waitFor(
        "document.querySelector('[data-test=blueprint-status]')?.textContent.includes('in preparation')",
    );

    await signIn(officer, `${examUrl()}/paper`);
    check(
        'while the blueprint is reopened the paper cannot be changed, and says why',
        (await has('[data-test=not-approved]')) &&
            !(await has('[data-test=fill-gaps]')) &&
            !(await has('[data-item] [data-test=remove]')),
        (await flat()).slice(0, 400),
    );
    await shot('4-blueprint-reopened');

    check(
        'every step of the paper is in the audit log',
        [
            'paper.created',
            'paper.drawn',
            'paper.item_removed',
            'paper.item_added',
            'paper.item_swapped',
            'paper.item_locked',
            'paper.settings_changed',
        ].every(
            (action) =>
                Number(
                    assess(
                        `SELECT COUNT(*) FROM sec_audit_logs WHERE action = '${action}'`,
                    ),
                ) > 0,
        ),
        assess(
            "SELECT GROUP_CONCAT(DISTINCT action) FROM sec_audit_logs WHERE action LIKE 'paper.%'",
        ),
    );
    check(
        'no JavaScript errors in the paper screens',
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
    'the test course is retired and the two test people deactivated',
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
