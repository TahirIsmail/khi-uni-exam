export type ExaminerRole = 'first' | 'second' | 'adjudicator';

export type MarkingExaminationRow = {
    id: number;
    reference: string;
    title: string;
    requireDoubleMarking: boolean;
    submittedCount: number;
    programme: string | null;
    year: string | null;
    intake: string | null;
    course: string | null;
};

export type ExaminerRow = {
    id: number;
    role: ExaminerRole;
    roleLabel: string;
    userId: number;
    name: string;
};

export type MarkingQueueRow = {
    attemptId: number;
    itemId: number;
    candidateNo: string;
    position: number;
    marks: number;
    peerHasMarked: boolean;
};

export type AdjudicationQueueRow = {
    attemptId: number;
    itemId: number;
    candidateNo: string;
    position: number;
    marks: number;
    examiner1: number;
    examiner2: number;
};

export type MarkingAbilities = {
    assign: boolean;
    mark: boolean;
    adjudicate: boolean;
};

export type MarkOption = { id: number; label: string; body: string };
export type MarkSubItem = { id: number; body: string };
export type MarkRubricCriterion = {
    id: number;
    criterion: string;
    maxMarks: number;
    guidance: string | null;
};

export type MarkableItem = {
    id: number;
    position: number;
    marks: number;
    typeCode: string;
    vignette: string | null;
    stem: string;
    leadIn: string | null;
    options: MarkOption[];
    items: MarkSubItem[];
    rubricCriteria: MarkRubricCriterion[];
};

export type CandidateAnswerPayload = {
    selected?: number[];
    items?: Record<number, boolean | number>;
    order?: number[];
    text?: string;
};

export type RecordedMark = {
    source: 'auto' | 'examiner_1' | 'examiner_2' | 'adjudicator' | 'final';
    sourceLabel: string;
    marksAwarded: number;
    comments: string | null;
    markedAt: string;
    criteria: { rubricCriterionId: number; marksAwarded: number }[];
};
