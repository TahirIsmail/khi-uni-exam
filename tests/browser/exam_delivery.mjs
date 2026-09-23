// Browser test of sitting an exam and resuming it on another computer (exam phase, step 18 —
// ADR-0003), in headless Chrome, through the screens a person really uses. Three separate Chrome
// processes stand in for three separate computers, each with its own cookies: the invigilator's,
// and the candidate's "first" and "second" machine.
//
//   tests/browser/on_test_database.sh tests/browser/exam_delivery.mjs
//   SHOTS=/some/folder tests/browser/on_test_database.sh tests/browser/exam_delivery.mjs
//
// Run it through on_test_database.sh: it writes an examination, candidates and answers that are not
// deleted by design, so it belongs in a throwaway database. In kmu-cms it uses test staff members and
// one test course with a topic, all cleaned up at the end.
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
const COURSE_CODE = 'E2E-DLV-101';
const SUPER_ADMIN_ROLE = 7;
const PEOPLE = {
    officer: {
        email: 'qbank-review-dlv-author-e2e@kmu.local',
        employee: 'TMP-RV-DA',
    },
    committee: {
        email: 'qbank-review-dlv-approver-e2e@kmu.local',
        employee: 'TMP-RV-DP',
    },
    controller: {
        email: 'qbank-review-dlv-academic-e2e@kmu.local',
        employee: 'TMP-RV-DC',
    },
};

if (!MYSQL_ASSESS || /\bkmu_assess$/.test(MYSQL_ASSESS)) {
    console.error(
        'This test writes candidates and answers, which are never deleted. Run it through tests/browser/on_test_database.sh.',
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

// ---- temporary CMS data -------------------------------------------------------------------------
const emails = Object.values(PEOPLE)
    .map((p) => `'${p.email}'`)
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

// The browser is started, and each of the three signs in, before anything below is seeded: the
// officer's, committee's and controller's kmu-assess users rows only exist from that first sign-in,
// and the examination and its paper need them as created_by/approved_by/etc.
const officerPort = 9370;
const machineAPort = 9371;
const machineBPort = 9372;

const officerChrome = makeChrome(officerPort, 'officer');
const machineAChrome = makeChrome(machineAPort, 'machine-a');
const machineBChrome = makeChrome(machineBPort, 'machine-b');

const officerCtx = await makeContext(officerPort, 'officer');
const machineA = await makeContext(machineAPort, 'machine-a');
const machineB = await makeContext(machineBPort, 'machine-b');
let examId = '0';

await officerCtx.signInStaff(officer, '/dashboard');
await officerCtx.signInStaff(committee, '/dashboard');
await officerCtx.signInStaff(controller, '/dashboard');

const professional = cms(
    'SELECT id FROM acad_professionals WHERE class_id = 1 ORDER BY sequence LIMIT 1',
);
cms(`SET @kmu_staff_id = ${officer};
    INSERT INTO acad_courses (course_code, title, class_id, professional_id, course_kind, status)
    VALUES ('${COURSE_CODE}', 'Delivery end-to-end course', 1, ${professional}, 'module', 'active')
    ON DUPLICATE KEY UPDATE status = 'active';`);
const courseId = cms(
    `SELECT id FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
);
cms(`INSERT INTO acad_curriculum_nodes (course_id, parent_id, level_type_id, code, name, path, depth, sort_order, is_active)
     VALUES (${courseId}, NULL, 4, 'E2E-DLV-MT1', 'Acute coronary syndrome', '/', 1, 1, 1);`);
const topicOne = cms(
    `SELECT id FROM acad_curriculum_nodes WHERE course_id = ${courseId}`,
);
const annual = cms("SELECT id FROM acad_exam_types WHERE code = 'annual'");
const sba = assess(
    "SELECT id FROM qb_question_types WHERE code = 'single_best_answer'",
);

// ---- a small, real bank of three single-best-answer questions, with options -------------------
const stamp = randomBytes(3).toString('hex');
for (let n = 1; n <= 3; n++) {
    const text = `Which finding number ${n} (${stamp}) fits this case?`;
    const ref = `Q-E2E-${stamp}-${String(n).padStart(3, '0')}`;
    assess(`INSERT INTO qb_questions (public_ref, branch_id, course_id, latest_version_no, times_used, is_archived, created_by, created_at, updated_at)
        VALUES ('${ref}', 1, ${courseId}, 1, 0, 0, (SELECT id FROM users WHERE cms_staff_id = ${officer}), NOW(), NOW())`);
    const qid = assess(
        `SELECT id FROM qb_questions WHERE public_ref = '${ref}'`,
    );
    // A version's options can only be written while it is still a draft (the question bank's own
    // immutability trigger, trg_qb_versions_frozen) — written and only then activated, the same
    // order the blueprint and the paper already have to be seeded in.
    assess(`INSERT INTO qb_question_versions (question_id, version_no, question_type_id, branch_id, stem, lead_in, marks, negative_marks, course_id, node_id, exam_type_id, status, content_hash, search_text, author_id, created_by, created_at, updated_at)
        VALUES (${qid}, 1, ${sba}, 1, '<p>${text}</p>', 'What next?', 1, 0, ${courseId}, ${topicOne}, ${annual}, 'draft', SHA2('${text}', 256), '${text}', (SELECT id FROM users WHERE cms_staff_id = ${officer}), (SELECT id FROM users WHERE cms_staff_id = ${officer}), NOW(), NOW())`);
    const vid = assess(
        `SELECT id FROM qb_question_versions WHERE question_id = ${qid}`,
    );
    const labels = ['A', 'B', 'C', 'D'];
    for (let i = 0; i < labels.length; i++) {
        const inserted =
            assess(`INSERT INTO qb_question_options (version_id, label, body, is_correct, sort_order, is_position_locked, created_at, updated_at)
            VALUES (${vid}, '${labels[i]}', 'Option ${labels[i]} for Q${n}', ${i === 0 ? 1 : 0}, ${i + 1}, 0, NOW(), NOW())`);
        if (inserted.startsWith('SQL ERROR')) {
            throw new Error(
                `Could not seed option ${labels[i]} for version ${vid}: ${inserted}`,
            );
        }
    }
    const seededOptions = assess(
        `SELECT COUNT(*) FROM qb_question_options WHERE version_id = ${vid}`,
    );
    check(
        `setup: question ${n} has its four options seeded`,
        seededOptions === '4',
        seededOptions,
    );
    assess(
        `UPDATE qb_question_versions SET status = 'active' WHERE id = ${vid}`,
    );
    assess(
        `UPDATE qb_questions SET active_version_id = ${vid} WHERE id = ${qid}`,
    );
}

// ---- browser --------------------------------------------------------------------------------
function makeChrome(port, profileTag) {
    return spawn(
        CHROME,
        [
            '--headless=new',
            `--remote-debugging-port=${port}`,
            `--user-data-dir=${mkdtempSync(join(tmpdir(), `delivery-e2e-${profileTag}-`))}`,
            '--ignore-certificate-errors',
            '--no-first-run',
            '--window-size=1400,1200',
            'about:blank',
        ],
        { stdio: 'ignore' },
    );
}

async function makeContext(port, name) {
    let target;
    for (let i = 0; i < 50 && !target; i++) {
        await sleep(200);
        try {
            target = (
                await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()
            ).find((t) => t.type === 'page');
        } catch {}
    }
    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((r) => ws.addEventListener('open', r));
    let nextId = 0;
    const pending = new Map();
    const jsErrors = [];
    const send = (method, params = {}) =>
        new Promise((r) => {
            const id = ++nextId;
            pending.set(id, r);
            ws.send(JSON.stringify({ id, method, params }));
        });
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
        if (
            m.method === 'Runtime.consoleAPICalled' &&
            m.params.type === 'error'
        )
            jsErrors.push(
                m.params.args.map((a) => a.value ?? a.description).join(' '),
            );
        if (m.method === 'Page.javascriptDialogOpening')
            void send('Page.handleJavaScriptDialog', { accept: true });
    });
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Emulation.setDeviceMetricsOverride', {
        width: 1400,
        height: 1200,
        deviceScaleFactor: 1,
        mobile: false,
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
    const setValue = async (selector, value) => {
        await evaluate(
            `(() => { const el = document.querySelector(${JSON.stringify(selector)}); el.focus(); const set = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(el), 'value').set; set.call(el, ${JSON.stringify(String(value))}); el.dispatchEvent(new Event('input', { bubbles: true })); return true; })()`,
        );
        await sleep(150);
    };
    const shot = async (shotName) => {
        if (SHOTS === null) return;
        mkdirSync(SHOTS, { recursive: true });
        await sleep(300);
        const { result } = await send('Page.captureScreenshot', {
            format: 'png',
            captureBeyondViewport: true,
        });
        writeFileSync(
            join(SHOTS, `${name}-${shotName}.png`),
            Buffer.from(result.data, 'base64'),
        );
    };
    const navigate = async (url, waitExpr) => {
        await send('Page.navigate', { url });
        return waitFor(waitExpr ?? 'document.body.innerText.length > 0');
    };
    const signInStaff = async (staffId, redirect) => {
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
    const signInCandidate = async (examId, candidateNo, pin) => {
        await navigate(
            `${ASSESS}/sit/${examId}`,
            "document.querySelector('[data-test=sit-login-form]')",
        );
        await setValue('[data-test=candidate-no]', candidateNo);
        await setValue('[data-test=pin]', pin);
        await click('[data-test=sit-login-submit]');
        await waitFor(
            `location.pathname !== '/sit/${examId}' || !!document.querySelector('[data-test=session-error], [data-test=pin-error]')`,
        );
        return path();
    };

    return {
        send,
        evaluate,
        waitFor,
        path,
        text,
        flat,
        has,
        click,
        setValue,
        shot,
        navigate,
        signInStaff,
        signInCandidate,
        jsErrors,
        close: () => ws.close(),
    };
}

// The exam-building screens (blueprint, paper, moderation) are exercised thoroughly by
// exam_moderation.mjs and exam_paper.mjs; here the paper is assembled directly, exactly as those
// tests already prove the application does it, so this file can focus on delivery itself.
assess(`INSERT INTO exm_examinations (public_ref, branch_id, title, programme_id, professional_id, course_id, exam_type_id, duration_minutes, total_marks, pass_percentage, negative_marking, status, created_by, created_at, updated_at)
    VALUES ('EX-E2E-${stamp}', 1, 'Delivery end-to-end examination', 1, ${professional}, ${courseId}, ${annual}, 60, 3, 50, 0, 'draft', (SELECT id FROM users WHERE cms_staff_id = ${officer}), NOW(), NOW())`);
examId = assess(
    `SELECT id FROM exm_examinations WHERE public_ref = 'EX-E2E-${stamp}'`,
);

assess(`INSERT INTO exm_blueprints (examination_id, branch_id, status, created_by, created_at, updated_at)
    VALUES (${examId}, 1, 'draft', (SELECT id FROM users WHERE cms_staff_id = ${officer}), NOW(), NOW())`);
const blueprintId = assess(
    `SELECT id FROM exm_blueprints WHERE examination_id = ${examId}`,
);
assess(`INSERT INTO exm_blueprint_rows (blueprint_id, sort_order, node_id, question_type_id, question_count, marks_each, created_at, updated_at)
    VALUES (${blueprintId}, 1, ${topicOne}, ${sba}, 3, 1, NOW(), NOW())`);
assess(
    `UPDATE exm_blueprints SET status = 'submitted', submitted_by = (SELECT id FROM users WHERE cms_staff_id = ${officer}), submitted_at = NOW() WHERE id = ${blueprintId}`,
);
assess(
    `UPDATE exm_blueprints SET status = 'approved', approved_by = (SELECT id FROM users WHERE cms_staff_id = ${officer}), approved_at = NOW(), approved_hash = SHA2('e2e-${stamp}', 256) WHERE id = ${blueprintId}`,
);
assess(
    `UPDATE exm_examinations SET status = 'blueprint_approved' WHERE id = ${examId}`,
);

assess(`INSERT INTO exm_papers (examination_id, version_no, status, shuffle_questions, shuffle_options, created_by, created_at, updated_at)
    VALUES (${examId}, 1, 'draft', 0, 0, (SELECT id FROM users WHERE cms_staff_id = ${officer}), NOW(), NOW())`);
const paperId = assess(
    `SELECT id FROM exm_papers WHERE examination_id = ${examId}`,
);
const questionIds = assess(
    `SELECT GROUP_CONCAT(id ORDER BY id) FROM qb_questions WHERE public_ref LIKE 'Q-E2E-${stamp}-%'`,
).split(',');
questionIds.forEach((qid, i) => {
    const vid = assess(
        `SELECT active_version_id FROM qb_questions WHERE id = ${qid}`,
    );
    const inserted =
        assess(`INSERT INTO exm_paper_items (paper_id, position, question_id, version_id, question_type_id, row_node_id, marks, is_locked, source, picked_by, created_at, updated_at)
        VALUES (${paperId}, ${i + 1}, ${qid}, ${vid}, ${sba}, ${topicOne}, 1, 0, 'manual', (SELECT id FROM users WHERE cms_staff_id = ${officer}), NOW(), NOW())`);
    if (inserted.startsWith('SQL ERROR')) {
        throw new Error(
            `Could not seed paper item for question ${qid} (version ${vid}): ${inserted}`,
        );
    }
});
const seededItems = assess(
    `SELECT COUNT(*) FROM exm_paper_items WHERE paper_id = ${paperId}`,
);
check(
    'setup: three paper items are seeded before the paper is published',
    seededItems === '3',
    seededItems,
);
assess(
    `UPDATE exm_papers SET status = 'submitted', submitted_by = (SELECT id FROM users WHERE cms_staff_id = ${officer}), submitted_at = NOW() WHERE id = ${paperId}`,
);
assess(
    `UPDATE exm_papers SET status = 'approved', approved_by = (SELECT id FROM users WHERE cms_staff_id = ${committee}), approved_at = NOW() WHERE id = ${paperId}`,
);
assess(
    `UPDATE exm_papers SET status = 'finalised', finalised_by = (SELECT id FROM users WHERE cms_staff_id = ${committee}), finalised_at = NOW(), content_hash = SHA2('e2e-${stamp}-paper', 256) WHERE id = ${paperId}`,
);
assess(
    `UPDATE exm_papers SET status = 'published', published_by = (SELECT id FROM users WHERE cms_staff_id = ${controller}), published_at = NOW() WHERE id = ${paperId}`,
);
check(
    'setup: the paper is published, ready for delivery',
    assess(`SELECT status FROM exm_papers WHERE id = ${paperId}`) ===
        'published',
);

// ---- a centre, a candidate, checked in — through the real screens -------------------------------
await officerCtx.navigate(
    `${ASSESS}/exams/conduct/centres`,
    "document.querySelector('[data-test=new-centre]')",
);
await officerCtx.click('[data-test=new-centre]');
await officerCtx.waitFor(
    "!!document.querySelector('[data-test=new-centre-form]')",
);
await officerCtx.setValue('#c-name', 'Delivery Hall');
await officerCtx.setValue('#c-code', `DH-${stamp}`);
await officerCtx.click('[data-test=save-centre]');
await officerCtx.waitFor(
    "document.querySelector('[data-centre]')?.textContent.includes('Delivery Hall')",
);
const centreId = assess(
    `SELECT id FROM cand_centres WHERE code = 'DH-${stamp}'`,
);
await officerCtx.click('[data-test=new-room]');
await officerCtx.waitFor(
    "!!document.querySelector('[data-test=new-room-form]')",
);
await officerCtx.setValue(`#r-name-${centreId}`, 'Delivery Room');
await officerCtx.setValue(`#r-cap-${centreId}`, '5');
await officerCtx.click('[data-test=save-room]');
await officerCtx.waitFor(
    "document.querySelector('[data-room]')?.textContent.includes('Delivery Room')",
);
const roomId = assess(
    `SELECT id FROM cand_rooms WHERE centre_id = ${centreId}`,
);

await officerCtx.navigate(
    `${ASSESS}/exams/${examId}/candidates`,
    "document.querySelector('[data-test=summary]')",
);
assess(`INSERT INTO cand_candidates (examination_id, branch_id, candidate_no, name, status, centre_id, room_id, allocated_by, allocated_at, created_by, created_at, updated_at)
    VALUES (${examId}, 1, 'C-${stamp}', 'Ayesha Khan', 'allocated', ${centreId}, ${roomId}, (SELECT id FROM users WHERE cms_staff_id = ${officer}), NOW(), (SELECT id FROM users WHERE cms_staff_id = ${officer}), NOW(), NOW())`);
const candidateId = assess(
    `SELECT id FROM cand_candidates WHERE candidate_no = 'C-${stamp}'`,
);

await officerCtx.navigate(
    `${ASSESS}/exams/${examId}/checkin`,
    "document.querySelector('[data-test=checkin-search]')",
);
await officerCtx.setValue('[data-test=checkin-search]', `C-${stamp}`);
await officerCtx.click('[data-test=checkin-find]');
await officerCtx.waitFor(
    `!!document.querySelector('[data-candidate="${candidateId}"]')`,
);
await officerCtx.click(
    `[data-candidate="${candidateId}"] [data-test=check-in]`,
);
await officerCtx.waitFor("!!document.querySelector('[data-test=pin-value]')");
const pin = await officerCtx.evaluate(
    "document.querySelector('[data-test=pin-value]')?.textContent.trim()",
);
check(
    'setup: the candidate is checked in with a PIN',
    /^\d{6}$/.test(pin ?? ''),
    pin,
);

try {
    // ---- machine A: the candidate sits down and answers two questions --------------------------
    check(
        'the candidate signs in and reaches the exam',
        (await machineA.signInCandidate(examId, `C-${stamp}`, pin)) ===
            `/sit/${examId}/exam`,
        await machineA.flat(),
    );
    await machineA.waitFor(
        "document.querySelectorAll('[data-item-nav]').length === 3",
    );
    await machineA.click('[data-test=option-A]');
    await machineA.waitFor(
        "document.querySelector('[data-test=save-status]')?.textContent.includes('Saved')",
    );
    check(
        'the first answer is saved to the server',
        assess(`SELECT COUNT(*) FROM dlv_answer_events`) !== '0',
    );

    await machineA.click('[data-item-nav]:nth-child(2)');
    await machineA.waitFor("!!document.querySelector('[data-test=option-B]')");
    await machineA.click('[data-test=option-B]');
    await machineA.click('[data-test=flag-item]');
    // Two changes were queued one after the other (the answer, then the flag): wait for the local
    // journal to be empty, not just for "Saved" to appear once — that can be true again as soon as
    // the first of the two has gone, before the second is actually sent.
    await machineA.waitFor(
        `JSON.parse(localStorage.getItem('sit-journal-${examId}') ?? '[]').length === 0`,
    );
    await machineA.shot('1-machine-a-answered');

    const attemptId = assess(
        `SELECT id FROM cand_candidate_exams WHERE candidate_id = ${candidateId}`,
    );
    const answeredCount = assess(
        `SELECT COUNT(*) FROM dlv_answers_current WHERE candidate_exam_id = ${attemptId}`,
    );
    const flaggedCount = assess(
        `SELECT COUNT(*) FROM dlv_answers_current WHERE candidate_exam_id = ${attemptId} AND flagged = 1`,
    );
    check(
        'both answers and the flag reached the server',
        answeredCount === '2' && flaggedCount === '1',
        `answered=${answeredCount} flagged=${flaggedCount} attemptId=${attemptId} rows=${assess(`SELECT cand_paper_item_id, payload, flagged, sequence_no FROM dlv_answers_current WHERE candidate_exam_id = ${attemptId}`)}`,
    );

    // ---- a second sign-in while machine A is still alive is blocked ----------------------------
    await machineB.signInCandidate(examId, `C-${stamp}`, pin);
    check(
        'signing in elsewhere while machine A is still active is blocked',
        await machineB.has('[data-test=session-error]'),
        await machineB.flat(),
    );

    // ---- the invigilator ends machine A's session (it "stopped responding") ---------------------
    await officerCtx.navigate(
        `${ASSESS}/exams/${examId}/monitor`,
        "document.body.innerText.includes('Monitor')",
    );
    check(
        'the monitor screen shows the candidate connected',
        await officerCtx.evaluate(
            `document.querySelector('[data-attempt="${attemptId}"]')?.textContent.includes('Connected')`,
        ),
    );
    await officerCtx.click(
        `[data-attempt="${attemptId}"] [data-test=end-session]`,
    );
    await officerCtx.waitFor(
        `!document.querySelector('[data-attempt="${attemptId}"]')?.textContent.includes('Connected')`,
    );

    // ---- machine B resumes the exact same attempt, with the answers already given ----------------
    check(
        'machine B resumes the exam after the invigilator ends the old session',
        (await machineB.signInCandidate(examId, `C-${stamp}`, pin)) ===
            `/sit/${examId}/exam`,
        await machineB.flat(),
    );
    await machineB.waitFor(
        "document.querySelectorAll('[data-item-nav]').length === 3",
    );
    check(
        'the first question still shows the answer given on machine A',
        await machineB.evaluate(
            "document.querySelector('[data-test=option-A]')?.checked === true",
        ),
    );
    await machineB.click('[data-item-nav]:nth-child(2)');
    check(
        'the flagged question is still flagged, with its answer, on machine B',
        (await machineB.evaluate(
            "document.querySelector('[data-test=option-B]')?.checked === true",
        )) &&
            (await machineB.evaluate(
                "document.querySelector('[data-test=flag-item]')?.textContent?.includes('Flagged')",
            )),
    );
    await machineB.shot('2-machine-b-resumed');

    // ---- the last question, then submit ----------------------------------------------------------
    await machineB.click('[data-item-nav]:nth-child(3)');
    await machineB.waitFor("!!document.querySelector('[data-test=option-C]')");
    await machineB.click('[data-test=option-C]');
    await machineB.waitFor(
        "document.querySelector('[data-test=save-status]')?.textContent.includes('Saved')",
    );

    await machineB.click('[data-test=submit-exam]');
    check(
        'submitting shows the submitted screen',
        await machineB.waitFor(
            `location.pathname === '/sit/${examId}/submitted'`,
        ),
        await machineB.flat(),
    );
    check(
        'the attempt is marked submitted, and its session ended',
        assess(
            `SELECT status FROM cand_candidate_exams WHERE id = ${attemptId}`,
        ) === 'submitted' &&
            assess(
                `SELECT COUNT(*) FROM dlv_sessions WHERE candidate_exam_id = ${attemptId} AND ended_at IS NULL`,
            ) === '0',
    );

    // ---- the database itself refuses to record another answer, and never deletes the attempt -----
    const somePaperItem = assess(
        `SELECT id FROM cand_paper_items WHERE candidate_exam_id = ${attemptId} LIMIT 1`,
    );
    const blockedInsert = assess(
        `INSERT INTO dlv_answer_events (candidate_exam_id, cand_paper_item_id, sequence_no, payload, created_at, updated_at) VALUES (${attemptId}, ${somePaperItem}, 999, '{}', NOW(), NOW())`,
    );
    check(
        'the database refuses an answer once the attempt is submitted',
        blockedInsert.includes('submitted'),
        blockedInsert,
    );
    const blockedDelete = assess(
        `DELETE FROM cand_candidate_exams WHERE id = ${attemptId}`,
    );
    check(
        'the database never deletes an attempt',
        blockedDelete.includes('never deleted'),
        blockedDelete,
    );

    check(
        'every step of delivery is in the audit log',
        [
            'candidate.exam_started',
            'candidate.session_ended_by_invigilator',
            'candidate.exam_submitted',
        ].every(
            (a) =>
                assess(
                    `SELECT COUNT(*) FROM sec_audit_logs WHERE action = '${a}'`,
                ) !== '0',
        ),
        assess(
            "SELECT GROUP_CONCAT(DISTINCT action) FROM sec_audit_logs WHERE action LIKE 'candidate.exam%' OR action LIKE 'candidate.session%'",
        ),
    );

    check(
        'no JavaScript errors on the candidate or invigilator screens',
        machineA.jsErrors.length === 0 &&
            machineB.jsErrors.length === 0 &&
            officerCtx.jsErrors.length === 0,
        [
            ...machineA.jsErrors,
            ...machineB.jsErrors,
            ...officerCtx.jsErrors,
        ].join(' | '),
    );
} catch (error) {
    check(
        `the test run stopped unexpectedly at ${await machineB.path()}`,
        false,
        error.stack,
    );
} finally {
    officerCtx?.close();
    machineA?.close();
    machineB?.close();
    officerChrome.kill();
    machineAChrome.kill();
    machineBChrome.kill();
    cleanup();
}

check(
    'the test course is retired and the test people deactivated',
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
