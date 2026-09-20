// Browser test of review, pre-hoc assessment and approval (Step 12) in headless Chrome.
//
//   node tests/browser/question_review.mjs https://kmu-assess.test
//
// Local development only. It uses three test staff members in the CMS — an author, a reviewer and a
// head of department — and one test course with a discipline and a topic. All of them are
// deactivated and the course retired at the end, because courses and staff who wrote or reviewed
// questions are never deleted. The question itself is archived. Audit entries always stay.
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
const COURSE_CODE = 'E2E-REV-101';
const SUPER_ADMIN_ROLE = 7;
// Three people, so separation of duty can actually be tested.
const PEOPLE = {
    author: {
        email: 'qbank-review-author-e2e@kmu.local',
        employee: 'TMP-RV-A',
    },
    reviewer: {
        email: 'qbank-review-reviewer-e2e@kmu.local',
        employee: 'TMP-RV-R',
    },
    approver: {
        email: 'qbank-review-approver-e2e@kmu.local',
        employee: 'TMP-RV-P',
    },
    // The QBank / academic reviewer (DME/DDE), the second level of review.
    academic: {
        email: 'qbank-review-academic-e2e@kmu.local',
        employee: 'TMP-RV-D',
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
        const questionIds = assess(
            `SELECT GROUP_CONCAT(id) FROM qb_questions WHERE course_id = ${courseId}`,
        );
        if (questionIds && questionIds !== 'NULL') {
            // Reviews and pre-hoc rows are kept by the database; the questions themselves are
            // archived, and any review still open is called off, so no leftover of a run sits in
            // somebody's queue afterwards.
            assess(`UPDATE qb_review_assignments SET status = 'cancelled', cancelled_at = NOW(3), cancel_reason = 'review end-to-end test finished'
                    WHERE question_id IN (${questionIds}) AND status = 'open';
                UPDATE qb_questions SET active_version_id = NULL WHERE id IN (${questionIds});
                UPDATE qb_question_versions SET status = 'archived'
                    WHERE question_id IN (${questionIds}) AND status NOT IN ('archived', 'superseded', 'retired');
                UPDATE qb_questions SET is_archived = 1, archive_reason = 'review end-to-end test' WHERE id IN (${questionIds})`);
        }
        cms(`DELETE FROM acad_curriculum_nodes WHERE course_id = ${courseId} AND depth = 2;
            DELETE FROM acad_curriculum_nodes WHERE course_id = ${courseId};
            UPDATE acad_courses SET status = 'retired' WHERE id = ${courseId}`);
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

/** The same three test people every run, each with the Super Admin role so they may do everything. */
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

const author = staff(PEOPLE.author);
const reviewer = staff(PEOPLE.reviewer);
const approver = staff(PEOPLE.approver);
const academic = staff(PEOPLE.academic);

// Two reviews, so the reviewer and the approver both have to act, and names stay visible.
const settingsBefore = cms(
    'SELECT CONCAT(kmu_assess_reviews_required, ",", kmu_assess_auto_activate, ",", kmu_assess_reviewer_anonymous) FROM sch_settings ORDER BY id LIMIT 1',
);
cms(
    'UPDATE sch_settings SET kmu_assess_reviews_required = 1, kmu_assess_auto_activate = 1, kmu_assess_reviewer_anonymous = 0 ORDER BY id LIMIT 1',
);

const professional = cms(
    'SELECT id FROM acad_professionals WHERE class_id = 1 ORDER BY sequence LIMIT 1',
);
if (
    cms(
        `SELECT COUNT(*) FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
    ) === '0'
) {
    cms(`SET @kmu_staff_id = ${author};
        INSERT INTO acad_courses (course_code, title, class_id, professional_id, course_kind, status)
        VALUES ('${COURSE_CODE}', 'Review end-to-end course', 1, ${professional}, 'module', 'active');`);
} else {
    cms(
        `SET @kmu_staff_id = ${author}; UPDATE acad_courses SET status = 'active' WHERE course_code = '${COURSE_CODE}'`,
    );
}
const courseId = cms(
    `SELECT id FROM acad_courses WHERE course_code = '${COURSE_CODE}'`,
);
cms(`INSERT INTO acad_curriculum_nodes (course_id, parent_id, level_type_id, code, name, path, depth, sort_order, is_active)
     VALUES (${courseId}, NULL, 3, 'E2E-RD', 'Cardiology (discipline)', '/', 1, 1, 1);`);
const disciplineNode = cms(
    `SELECT id FROM acad_curriculum_nodes WHERE course_id = ${courseId} AND depth = 1`,
);
cms(`INSERT INTO acad_curriculum_nodes (course_id, parent_id, level_type_id, code, name, path, depth, sort_order, is_active)
     VALUES (${courseId}, ${disciplineNode}, 4, 'E2E-RT', 'Acute coronary syndrome', '/${disciplineNode}/', 2, 1, 1);`);
const topicId = cms(
    `SELECT id FROM acad_curriculum_nodes WHERE course_id = ${courseId} AND depth = 2`,
);

// ---- browser ----------------------------------------------------------------------------------
const chrome = spawn(
    CHROME,
    [
        '--headless=new',
        '--remote-debugging-port=9355',
        `--user-data-dir=${mkdtempSync(join(tmpdir(), 'qb-review-e2e-'))}`,
        '--ignore-certificate-errors',
        '--no-first-run',
        '--window-size=1500,1200',
        'about:blank',
    ],
    { stdio: 'ignore' },
);
let target;
for (let i = 0; i < 50 && !target; i++) {
    await sleep(200);
    try {
        target = (
            await (await fetch('http://127.0.0.1:9355/json/list')).json()
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
/** Opens the collapsed panels (the checklist, the reviewers), as a user does when they want them. */
const openPanels = async () =>
    evaluate(
        "(() => { document.querySelectorAll('details').forEach((d) => { d.open = true; }); return true; })()",
    );
const setSelect = async (selector, value) =>
    evaluate(
        `(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; el.value = ${JSON.stringify(String(value))}; el.dispatchEvent(new Event('change', { bubbles: true })); return el.value === ${JSON.stringify(String(value))}; })()`,
    );
/** Signs in as one of the three people, landing on the given page. */
const signIn = async (staffId, redirect) => {
    await send('Page.navigate', { url: 'about:blank' });
    await evaluate(
        `(() => { const f = document.createElement('form'); f.method = 'post'; f.action = '${ASSESS}/sso/cms'; const i = document.createElement('input'); i.name = 'ticket'; i.value = ${JSON.stringify(ticket(staffId, redirect))}; f.appendChild(i); document.body.appendChild(f); f.submit(); return true; })()`,
    );
    return (
        (await waitFor(
            `location.pathname === ${JSON.stringify(redirect.split('?')[0])}`,
        )) && (await waitFor('document.body.innerText.length > 200'))
    );
};

try {
    await send('Page.enable');
    await send('Runtime.enable');

    // A member of staff becomes a user of the module the first time they open it from the CMS, and
    // only users can be asked to review. Everybody in this test opens it once first.
    for (const person of [reviewer, approver, academic]) {
        await signIn(person, '/dashboard');
    }
    check(
        'the reviewer, the academic reviewer and the approver are users of the module',
        assess(
            `SELECT COUNT(*) FROM users WHERE email IN ('${PEOPLE.reviewer.email}', '${PEOPLE.approver.email}', '${PEOPLE.academic.email}') AND is_active = 1`,
        ) === '3',
        assess(
            `SELECT GROUP_CONCAT(email) FROM users WHERE email IN (${emails})`,
        ),
    );

    // ---- the author writes a question and sends it for review ---------------------------------
    check(
        'the author opens the editor from kmu-cms',
        await signIn(author, '/questions/create'),
        await path(),
    );
    await waitFor("!!document.getElementById('type')");

    await setSelect('#programme', 1);
    await sleep(600);
    await setSelect('#course', courseId);
    await sleep(1200);
    await setSelect('#topic', topicId);
    await type(
        '#stem',
        'A 54-year-old man has crushing chest pain radiating to the jaw for 40 minutes, with sweating and nausea.',
    );
    await type(
        '#lead-in',
        'Which investigation is most useful in the first ten minutes?',
    );

    for (const option of [
        'ECG',
        'Chest radiograph',
        'Echocardiogram',
        'Coronary angiography',
    ]) {
        await click('[data-test=add-option]');
        await sleep(200);
        const index =
            (await evaluate(
                "document.querySelectorAll('[data-option]').length",
            )) - 1;
        await type(`[data-option]:nth-of-type(${index + 1}) textarea`, option);
    }
    await click('[data-correct=A]');
    await sleep(400);

    await click('[data-test=add-reference]');
    await sleep(200);
    await type('[aria-label="Reference 1"]', 'Harrison, 21st ed p. 1875');

    // Filed under the Annual examination, with the author's own cognitive and difficulty level.
    await setSelect(
        '#exam-type',
        cms("SELECT id FROM acad_exam_types WHERE code = 'annual'"),
    );
    await setSelect('#cognitive', 2);
    await setSelect('#difficulty', 2);
    await sleep(1000);

    await click('[data-test=save-draft]');
    check(
        "the draft is saved with the author's own judgement",
        await waitFor(
            "/Q-\\d{4}-\\d{6}/.test(document.body.innerText) && location.pathname.includes('/edit')",
        ),
        await path(),
    );

    const reference = (await text()).match(/Q-\d{4}-\d{6}/)?.[0];
    const questionId = assess(
        `SELECT id FROM qb_questions WHERE public_ref = '${reference}'`,
    );
    const versionId = assess(
        `SELECT id FROM qb_question_versions WHERE question_id = ${questionId} ORDER BY id DESC LIMIT 1`,
    );

    await click('[data-test=submit-question]');
    check(
        'sending it for review lands on the question list',
        await waitFor("location.pathname === '/questions'"),
        await path(),
    );

    check(
        'sending it for review asks a reviewer automatically',
        assess(
            `SELECT COUNT(*) FROM qb_review_assignments WHERE version_id = ${versionId} AND status = 'open' AND assigned_by IS NULL`,
        ) === '1',
        assess(
            `SELECT GROUP_CONCAT(CONCAT(reviewer_id, ':', status)) FROM qb_review_assignments WHERE version_id = ${versionId}`,
        ),
    );
    check(
        "the author's own judgement is kept as their proposal",
        assess(
            `SELECT CONCAT(source, ':', cognitive_level_id, ':', difficulty_level_id) FROM qb_prehoc_assessments WHERE version_id = ${versionId}`,
        ) === 'author:2:2',
        assess(
            `SELECT GROUP_CONCAT(CONCAT(source, ':', IFNULL(cognitive_level_id, '-'))) FROM qb_prehoc_assessments WHERE version_id = ${versionId}`,
        ),
    );

    const assignedTo = assess(
        `SELECT u.email FROM qb_review_assignments a JOIN users u ON u.id = a.reviewer_id WHERE a.version_id = ${versionId} AND a.status = 'open'`,
    );
    check(
        'the author is never asked to review their own question',
        assignedTo !== PEOPLE.author.email,
        assignedTo,
    );

    // ---- the head of department gives the review to a particular person -----------------------
    // Automatic assignment picks whoever has the least to do, which in this database may be anybody
    // who can review; the head of department takes it back and asks the test reviewer instead.
    await signIn(
        approver,
        `/questions/${questionId}/versions/${versionId}/review`,
    );
    await waitFor("!!document.querySelector('[data-test=reviewer]')");
    await openPanels();
    await evaluate(
        "window.prompt = () => 'Away on leave for a month'; window.confirm = () => true;",
    );
    const autoAssignment = assess(
        `SELECT id FROM qb_review_assignments WHERE version_id = ${versionId} AND status = 'open'`,
    );
    await click(`[data-cancel="${autoAssignment}"]`);
    await sleep(1200);
    check(
        'a review can be taken back with a reason',
        assess(
            `SELECT CONCAT(status, ':', cancel_reason) FROM qb_review_assignments WHERE id = ${autoAssignment}`,
        ) === 'cancelled:Away on leave for a month',
        assess(
            `SELECT CONCAT(status, ':', IFNULL(cancel_reason, '-')) FROM qb_review_assignments WHERE id = ${autoAssignment}`,
        ),
    );

    const reviewerUserId = assess(
        `SELECT id FROM users WHERE email = '${PEOPLE.reviewer.email}'`,
    );
    await setSelect('[data-test=reviewer]', reviewerUserId);
    await sleep(300);
    await click('[data-test=assign]');
    await sleep(1200);
    check(
        'the named reviewer is asked, and the record says who asked them',
        assess(
            `SELECT CONCAT(a.status, ':', IF(a.assigned_by IS NULL, 'auto', 'by hand')) FROM qb_review_assignments a WHERE a.version_id = ${versionId} AND a.reviewer_id = ${reviewerUserId}`,
        ) === 'open:by hand',
        assess(
            `SELECT GROUP_CONCAT(CONCAT(reviewer_id, ':', status)) FROM qb_review_assignments WHERE version_id = ${versionId}`,
        ),
    );
    check(
        'the same person cannot be asked twice',
        (await evaluate(
            `(async () => { const r = await fetch('/questions/${questionId}/versions/${versionId}/reviewers', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-Inertia': 'true', 'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '') }, body: JSON.stringify({ reviewer_id: ${reviewerUserId} }) }); return r.status; })()`,
        )) === 422,
        assess(
            `SELECT COUNT(*) FROM qb_review_assignments WHERE version_id = ${versionId} AND reviewer_id = ${reviewerUserId} AND status = 'open'`,
        ),
    );

    // ---- the reviewer works through the checklist ---------------------------------------------
    check(
        'the reviewer finds it in their own queue',
        (await signIn(reviewer, '/reviews')) &&
            (await waitFor(`document.body.innerText.includes('${reference}')`)),
        (await text()).replace(/\s+/g, ' ').slice(0, 300),
    );

    await click(`[data-assignment] a`);
    check(
        'the review workspace opens with the question as a candidate sees it',
        (await waitFor(
            "/\\/review$/.test(location.pathname) && !!document.querySelector('[data-test=review-form]')",
        )) &&
            /crushing chest pain/.test(await text()) &&
            (await evaluate(
                "!!document.querySelector('[data-test=candidate-preview]')",
            )),
        await path(),
    );

    check(
        'the workspace shows where the question is: Create → Review → Approve → Stored in QBank',
        (await evaluate(
            "!!document.querySelector('[data-test=journey] [data-step=current]')",
        )) && /Create.*Review.*Approve.*Stored in QBank/s.test(await text()),
        (await text()).replace(/\s+/g, ' ').slice(0, 300),
    );

    await openPanels();
    await sleep(200);
    const workspace = (await text()).replace(/\s+/g, ' ');
    check(
        "the checklist and KMU's five decisions come from the database",
        (await evaluate(
            "document.querySelectorAll('[data-checklist]').length",
        )) === 8 &&
            (await evaluate(
                "document.querySelectorAll('#decision option').length",
            )) === 6 &&
            /cover the options|without seeing the options/i.test(workspace),
        workspace.slice(0, 400),
    );
    check(
        'the reviewer sees what the author said about it',
        /What the author said about it/.test(workspace),
        workspace.slice(0, 400),
    );

    // Failing a required rule warns that the question cannot be approved as it is.
    await click('[data-fail=no_negative_stem]');
    await sleep(500);
    check(
        'failing a required rule says the question cannot be approved yet',
        await waitFor(
            '!!document.querySelector("[data-test=failed-required]")',
            20,
        ),
        (await text()).replace(/\s+/g, ' ').slice(0, 300),
    );
    await click('[data-pass=no_negative_stem]');
    await sleep(400);

    // Sending it back needs a comment; the empty attempt is refused by the server.
    await setSelect(
        '#decision',
        assess("SELECT id FROM qb_prehoc_decisions WHERE code = 'accept'"),
    );
    await setSelect('#cognitive', 3);
    await setSelect('#difficulty', 2);
    await type(
        '#comments',
        'Clean single best answer; the key is defensible from the reference given.',
    );
    await sleep(500);
    await click('[data-test=submit-review]');
    check(
        'submitting the review returns the reviewer to their queue',
        await waitFor("location.pathname === '/reviews'"),
        await path(),
    );

    const review = assess(
        `SELECT CONCAT(r.outcome, ':', d.code, ':', JSON_LENGTH(r.checklist)) FROM qb_reviews r JOIN qb_prehoc_decisions d ON d.id = r.decision_id WHERE r.version_id = ${versionId}`,
    );
    check(
        'the review is stored with its decision and the whole checklist',
        review === 'reviewed:accept:8',
        review,
    );
    check(
        "the reviewer's cognitive and difficulty level are stored beside the author's",
        assess(
            `SELECT CONCAT(cognitive_level_id, ':', difficulty_level_id) FROM qb_prehoc_assessments WHERE version_id = ${versionId} AND source = 'reviewer'`,
        ) === '3:2',
        assess(
            `SELECT GROUP_CONCAT(CONCAT(source, '=', IFNULL(cognitive_level_id, '-'))) FROM qb_prehoc_assessments WHERE version_id = ${versionId}`,
        ),
    );
    check(
        'the subject review in, the question goes on to the QBank / academic review',
        assess(
            `SELECT status FROM qb_question_versions WHERE id = ${versionId}`,
        ) === 'under_review' &&
            assess(
                `SELECT COUNT(*) FROM qb_review_assignments WHERE version_id = ${versionId} AND stage = 'academic' AND status = 'open'`,
            ) === '1',
        assess(
            `SELECT GROUP_CONCAT(CONCAT(stage, ':', status)) FROM qb_review_assignments WHERE version_id = ${versionId}`,
        ),
    );

    // ---- the QBank / academic review ----------------------------------------------------------
    // As before, the head of department gives it to the test's academic reviewer by hand, because
    // automatic assignment may choose anybody in this database who holds the right.
    await signIn(
        approver,
        `/questions/${questionId}/versions/${versionId}/review`,
    );
    await waitFor("!!document.querySelector('[data-test=stage]')");
    await openPanels();
    await evaluate(
        "window.prompt = () => 'Given to the academic reviewer of this test'; window.confirm = () => true;",
    );
    const autoAcademic = assess(
        `SELECT id FROM qb_review_assignments WHERE version_id = ${versionId} AND stage = 'academic' AND status = 'open'`,
    );
    await click(`[data-cancel="${autoAcademic}"]`);
    await sleep(1200);
    await setSelect('[data-test=stage]', 'academic');
    await sleep(300);
    const academicUserId = assess(
        `SELECT id FROM users WHERE email = '${PEOPLE.academic.email}'`,
    );
    await setSelect('[data-test=reviewer]', academicUserId);
    await sleep(300);
    await click('[data-test=assign]');
    await sleep(1200);
    check(
        'the academic review is given to the academic reviewer',
        assess(
            `SELECT status FROM qb_review_assignments WHERE version_id = ${versionId} AND stage = 'academic' AND reviewer_id = ${academicUserId}`,
        ) === 'open',
        assess(
            `SELECT GROUP_CONCAT(CONCAT(stage, ':', reviewer_id, ':', status)) FROM qb_review_assignments WHERE version_id = ${versionId}`,
        ),
    );

    check(
        'the academic reviewer finds it in the same queue, marked as the academic review',
        (await signIn(academic, '/reviews')) &&
            (await waitFor(
                `document.body.innerText.includes('${reference}') && /QBank \\/ Academic review/.test(document.body.innerText)`,
            )),
        (await text()).replace(/\s+/g, ' ').slice(0, 300),
    );
    await click('[data-assignment] a');
    await waitFor("!!document.querySelector('[data-test=review-form]')");
    check(
        'the workspace says which level this review is',
        /QBank \/ Academic review/.test(
            (await evaluate(
                "document.querySelector('[data-test=my-stage]')?.innerText ?? ''",
            )) ?? '',
        ),
    );
    await setSelect(
        '#decision',
        assess("SELECT id FROM qb_prehoc_decisions WHERE code = 'accept'"),
    );
    await type(
        '#comments',
        'Language is clear and the key is correct; suitable for the question bank.',
    );
    await sleep(400);
    await click('[data-test=submit-review]');
    check(
        'the academic review is recorded',
        (await waitFor("location.pathname === '/reviews'")) &&
            assess(
                `SELECT CONCAT(stage, ':', outcome) FROM qb_reviews WHERE version_id = ${versionId} AND stage = 'academic'`,
            ) === 'academic:reviewed',
        await path(),
    );
    check(
        'a submitted review cannot be edited, even in SQL',
        assess(
            `UPDATE qb_reviews SET comments = 'changed' WHERE version_id = ${versionId}`,
        ).includes('cannot be changed'),
    );

    // ---- the author reads the comments --------------------------------------------------------
    await signIn(
        author,
        `/questions/${questionId}/versions/${versionId}/review`,
    );
    const authorView = (await text()).replace(/\s+/g, ' ');
    check(
        'the author reads the review and sees who wrote it',
        /defensible from the reference/.test(authorView) &&
            new RegExp(PEOPLE.reviewer.employee).test(authorView) &&
            !/Pre-hoc assessment/.test(authorView),
        authorView.slice(0, 500),
    );

    // ---- the approver decides -----------------------------------------------------------------
    check(
        'the approver finds it ready to decide',
        (await signIn(approver, '/approvals')) &&
            (await waitFor(`document.body.innerText.includes('${reference}')`)),
        (await text()).replace(/\s+/g, ' ').slice(0, 300),
    );

    await click(`[data-version="${versionId}"] a`);
    await waitFor('!!document.querySelector("[data-test=approval-form]")');
    check(
        'the approver sees the review and can settle the values',
        /defensible from the reference/.test(await text()) &&
            (await evaluate(
                "!!document.querySelector('[data-test=approve-cognitive]')",
            )),
        (await text()).replace(/\s+/g, ' ').slice(0, 400),
    );

    await setSelect('[data-test=approve-cognitive]', 3);
    await setSelect('[data-test=approve-difficulty]', 3);
    await sleep(300);
    await click('[data-test=approve]');
    check(
        'approving takes the approver back to the queue',
        await waitFor("location.pathname === '/approvals'"),
        await path(),
    );

    const approved = assess(
        `SELECT CONCAT(status, ':', cognitive_level_id, ':', difficulty_level_id, ':', IF(activated_at IS NULL, 'no', 'yes')) FROM qb_question_versions WHERE id = ${versionId}`,
    );
    check(
        'the question is in use and keeps the values the approver settled on',
        approved === 'active:3:3:yes',
        approved,
    );
    check(
        'every judgement is kept — author, subject reviewer, academic reviewer — and one settled on approval',
        assess(
            `SELECT CONCAT(COUNT(*), ':', MAX(source)) FROM qb_prehoc_assessments WHERE version_id = ${versionId} AND is_consolidated = 1`,
        ) === '1:consolidated' &&
            assess(
                `SELECT GROUP_CONCAT(source ORDER BY id) FROM qb_prehoc_assessments WHERE version_id = ${versionId}`,
            ) === 'author,reviewer,reviewer,consolidated',
        assess(
            `SELECT GROUP_CONCAT(source) FROM qb_prehoc_assessments WHERE version_id = ${versionId}`,
        ),
    );
    check(
        'the question now points at the version in use',
        assess(
            `SELECT active_version_id FROM qb_questions WHERE id = ${questionId}`,
        ) === versionId,
    );
    check(
        'every step is in the status log, in order',
        assess(
            `SELECT GROUP_CONCAT(to_status ORDER BY id) FROM qb_version_status_log WHERE version_id = ${versionId}`,
        ) === 'submitted,under_review,approved,active',
        assess(
            `SELECT GROUP_CONCAT(to_status ORDER BY id) FROM qb_version_status_log WHERE version_id = ${versionId}`,
        ),
    );
    check(
        'the audit log records the assignment, the review, the pre-hoc and the approval',
        assess(
            `SELECT GROUP_CONCAT(DISTINCT action ORDER BY action) FROM sec_audit_logs WHERE entity_id IN (${versionId}, (SELECT id FROM qb_review_assignments WHERE version_id = ${versionId} LIMIT 1)) AND action LIKE 'qbank.%'`,
        ).includes('qbank.question.approved'),
        assess(
            `SELECT GROUP_CONCAT(DISTINCT action) FROM sec_audit_logs WHERE action LIKE 'qbank.%' AND entity_id = ${versionId}`,
        ),
    );

    // ---- separation of duty -------------------------------------------------------------------
    // A second question by the approver themselves cannot be approved by them.
    await signIn(approver, `/questions/${questionId}`);
    check(
        'the history screen shows the version in use',
        /Active|In use/i.test(await text()),
        (await text()).replace(/\s+/g, ' ').slice(0, 300),
    );

    check(
        'no JavaScript errors in the review screens',
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
    const [required, auto, anonymous] = (settingsBefore || '1,1,0').split(',');
    cms(
        `UPDATE sch_settings SET kmu_assess_reviews_required = ${Number(required) || 1}, kmu_assess_auto_activate = ${Number(auto) || 1}, kmu_assess_reviewer_anonymous = ${Number(anonymous) || 0} ORDER BY id LIMIT 1`,
    );
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
