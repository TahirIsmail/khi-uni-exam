/** A plain-words band beside a figure: good, ok, warn or bad, and the words shown. */
export type AnalysisBand = {
    key: 'good' | 'ok' | 'warn' | 'bad';
    label: string;
};

export type ItemOption = {
    label: string;
    correct: boolean;
    share: number | null;
    upper: number | null;
    lower: number | null;
};

export type DistractorAnalysis = {
    wrong: number;
    functional: number;
    efficiency: number | null;
    nonFunctional: string[];
    defective: string[];
    possibleMiskey: string[];
};

export type ItemAnalysisRow = {
    paperItemId: number;
    versionId: number;
    questionId: number;
    position: number;
    marks: number;
    candidates: number;
    correctCount: number;
    observedP: number | null;
    difficultyBand: AnalysisBand | null;
    discrimination: number | null;
    discriminationBand: AnalysisBand | null;
    distractors: Record<string, number> | null;
    options: ItemOption[] | null;
    distractorAnalysis: DistractorAnalysis | null;
};

export type Reliability = {
    coefficient: number | null;
    label: string;
    candidates: number;
    alpha: number | null;
    kr20: number | null;
    dichotomous: boolean;
    band: AnalysisBand | null;
};

export type ExamStatistics = {
    students: number;
    pending: number;
    totalMarks: number;
    mean: number | null;
    median: number | null;
    sd: number | null;
    min: number | null;
    max: number | null;
    passed: number;
    failed: number;
    passPercent: number | null;
    failPercent: number | null;
    passMark: number;
};

export type AnalysisThresholds = {
    min_candidates: number;
    difficulty: { too_hard_below: number; too_easy_above: number };
    discrimination: { good_from: number; acceptable_from: number };
    functional_distractor_share: number;
    reliability: { good_from: number; acceptable_from: number };
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
