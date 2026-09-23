export type ItemAnalysisRow = {
    paperItemId: number;
    versionId: number;
    questionId: number;
    position: number;
    marks: number;
    candidates: number;
    correctCount: number;
    observedP: number | null;
    discrimination: number | null;
    distractors: Record<string, number> | null;
};

export type Reliability = {
    coefficient: number | null;
    label: string;
    candidates: number;
};

export type TosRow = {
    nodeId: number;
    questionTypeId: number;
    plannedCount: number;
    deliveredCount: number;
    plannedMarks: number;
    deliveredMarks: number;
    compliant: boolean;
};

export type TosMix = {
    dimension: 'cognitive' | 'difficulty';
    levelId: number;
    plannedPercent: number;
    deliveredPercent: number;
};

export type PosthocDecisionOption = {
    code: string;
    name: string;
    description: string | null;
};

export type AnalyticsAbilities = {
    run: boolean;
    decide: boolean;
};
