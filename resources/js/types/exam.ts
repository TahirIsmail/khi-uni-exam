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
    };
    forEditing: boolean;
};
