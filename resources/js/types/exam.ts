import type { CourseOption, ExamTypeOption, YearOption } from './qbank';

/** The stage of an examination's blueprint, which is how the examination's progress is told. */
export type BlueprintStatus = 'draft' | 'submitted' | 'approved';

export type ExaminationListRow = {
    id: number;
    reference: string;
    title: string;
    programme: string;
    year: string;
    examType: string;
    course: string;
    startsAt: string | null;
    durationMinutes: number;
    totalMarks: number;
    blueprintStatus: BlueprintStatus;
    blueprintLabel: string;
    plannedQuestions: number;
    plannedMarks: number;
};

export type ExaminationDetail = {
    id: number;
    reference: string;
    title: string;
    programmeId: number;
    programme: string;
    professionalId: number;
    termId: number | null;
    year: string;
    courseId: number;
    course: string;
    examTypeId: number;
    examType: string;
    intakeId: number | null;
    intake: string | null;
    /** As typed into a date-and-time field, in the examination time zone. */
    startsAt: string | null;
    startsAtLabel: string | null;
    durationMinutes: number;
    totalMarks: number;
    passPercentage: number;
    passMarks: number;
    negativeMarking: boolean;
    negativeFraction: number | null;
    instructions: string | null;
    status: string;
    statusLabel: string;
    createdBy: string | null;
};

/** What a person may choose when setting an examination up. */
export type ExamChoices = {
    programmes: { id: number; name: string; code: string }[];
    years: YearOption[];
    programmeCalendars: Record<number, string>;
    courses: CourseOption[];
    examTypes: ExamTypeOption[];
    intakes: { id: number; name: string }[];
    defaults: { durationMinutes: number; passPercentage: number };
    limits: { durationMin: number; durationMax: number; marksMax: number };
    timezone: string;
};

export type ExamAbilities = {
    edit: boolean;
    editBlueprint: boolean;
    submit: boolean;
    approve: boolean;
    sendBack: boolean;
    reopen: boolean;
};

export type BlueprintRowData = {
    /** The position of its section in the list of sections, or null when it has none. */
    section: number | null;
    node_id: number;
    question_type_id: number;
    question_count: number;
    marks_each: number;
};

export type BlueprintTargetData = { level_id: number; percent: number };

export type BlueprintData = {
    id: number;
    status: BlueprintStatus;
    statusLabel: string;
    returnReason: string | null;
    createdBy: string | null;
    submittedBy: string | null;
    submittedAt: string | null;
    approvedBy: string | null;
    approvedAt: string | null;
    fingerprint: string | null;
    sections: string[];
    rows: BlueprintRowData[];
    cognitive: BlueprintTargetData[];
    difficulty: BlueprintTargetData[];
};

export type BlueprintReport = {
    plannedQuestions: number;
    plannedMarks: number;
    totalMarks: number;
    difference: number;
    isBalanced: boolean;
    blockers: string[];
    warnings: string[];
    targets: { cognitive: number; difficulty: number };
};

export type BlueprintTopic = {
    id: number;
    parentId: number | null;
    label: string;
    name: string;
    level: string;
    depth: number;
};

export type BlueprintScreen = {
    blueprint: BlueprintData;
    report: BlueprintReport;
    topics: BlueprintTopic[];
    types: { id: number; code: string; name: string; family: string }[];
    cognitiveLevels: { id: number; name: string }[];
    difficultyLevels: { id: number; name: string }[];
    /** topic id → type id → questions in use in the question bank. */
    availability: Record<number, Record<number, number>>;
    limits: {
        maxRows: number;
        maxSections: number;
        maxCount: number;
        marksMax: number;
        /** A bank with fewer questions than the rows ask for stops the blueprint being submitted. */
        requireBank: boolean;
    };
    forEditing: boolean;
};

export type PaperItemData = {
    id: number;
    questionId: number;
    reference: string;
    slotKey: string;
    marks: number;
    /** Null when the person may count the paper but not read its questions. */
    summary: string | null;
    typeName: string;
    versionNo: number;
    cognitive: string | null;
    cognitiveId: number | null;
    difficulty: string | null;
    difficultyId: number | null;
    timesUsed: number;
    lastUsed: string | null;
    isLocked: boolean;
    source: 'auto' | 'manual';
    flags: ('recent' | 'own' | 'newer' | 'gone' | 'same_text')[];
    latestVersionNo: number | null;
};

export type PaperRowData = {
    key: string;
    nodeId: number;
    typeId: number;
    marks: number;
    section: string | null;
    count: number;
    topic: string;
    typeName: string;
    items: PaperItemData[];
    missing: number;
    over: number;
    /** Questions the bank could still give this row; null when the person may not read questions. */
    available: number | null;
};

export type PaperCandidate = {
    questionId: number;
    reference: string;
    summary: string;
    topic: string;
    cognitive: string | null;
    difficulty: string | null;
    timesUsed: number;
    lastUsed: string | null;
    usedRecently: boolean;
    sameTextInPaper: boolean;
    mine: boolean;
    versionNo: number;
};

export type PaperMixRow = {
    id: number;
    name: string;
    target: number | null;
    count: number;
    actual: number;
};

export type PaperStatus =
    | 'draft'
    | 'submitted'
    | 'approved'
    | 'finalised'
    | 'published';

export type PaperCommentData = {
    id: number;
    itemId: number | null;
    itemReference: string | null;
    body: string;
    status: 'open' | 'resolved';
    createdBy: string | null;
    createdAt: string | null;
    resolvedBy: string | null;
    resolvedAt: string | null;
};

export type PaperVersionData = {
    id: number;
    versionNo: number;
    status: PaperStatus;
    statusLabel: string;
    isCurrent: boolean;
};

export type PaperAbilities = {
    start: boolean;
    edit: boolean;
    submit: boolean;
    approve: boolean;
    sendBack: boolean;
    finalise: boolean;
    publish: boolean;
    unlockVersion: boolean;
    comment: boolean;
    resolveComments: boolean;
};

export type PaperReport = {
    isComplete: boolean;
    blockers: string[];
    advisories: string[];
};

export type PaperScreen = {
    paper: {
        id: number;
        versionNo: number;
        status: PaperStatus;
        statusLabel: string;
        shuffleQuestions: boolean;
        shuffleOptions: boolean;
        blueprintChanged: boolean;
        returnReason: string | null;
    } | null;
    rows: PaperRowData[];
    unassigned: PaperItemData[];
    totals: {
        chosen: number;
        planned: number;
        marks: number;
        plannedMarks: number;
        totalMarks: number;
    };
    mix: { cognitive: PaperMixRow[]; difficulty: PaperMixRow[] };
    warnings: { kind: string; message: string; references: string[] }[];
    mayRead: boolean;
    can: PaperAbilities;
    blueprintApproved: boolean;
    limits: { candidates: number; recentMonths: number };
    report: PaperReport | null;
    comments: PaperCommentData[];
    versions: PaperVersionData[];
};

export type PaperSummary = {
    exists: boolean;
    chosen: number;
    planned: number;
    marks: number;
    plannedMarks: number;
};
