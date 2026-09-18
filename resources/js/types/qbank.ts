export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

export type SettingValue = string | number | boolean | null;

export type SettingMap = Record<string, SettingValue>;

export type QuestionTypeInfo = {
    id: number;
    code: string;
    name: string;
    family: string;
    description: string;
    hasOptions: boolean;
    optionsMin: number;
    optionsMax: number;
    correctMin: number;
    correctMax: number | null;
    hasItems: boolean;
    itemsMin: number;
    itemsMax: number;
    itemAnswer: 'none' | 'boolean' | 'option' | 'text' | 'position';
    hasAcceptedAnswers: boolean;
    hasNumericAnswer: boolean;
    isManuallyMarked: boolean;
    supportsShuffle: boolean;
    supportsPartialCredit: boolean;
    supportsNegativeMarks: boolean;
    supportsRubric: boolean;
    defaultSettings: SettingMap;
};

export type CourseOption = {
    id: number;
    code: string;
    title: string;
    programme_id: number;
    professional_id: number | null;
    term_id: number | null;
};

export type CurriculumNode = {
    id: number;
    parent_id: number | null;
    name: string;
    code: string | null;
    path: string;
    depth: number;
    level: string;
    discipline_id: number | null;
    allows_questions: boolean;
};

export type OptionRow = {
    label: string;
    body: string;
    is_correct: boolean;
    weight: number | null;
    feedback: string | null;
    sort_order: number;
    is_position_locked: boolean;
    item_index: number | null;
};

export type ItemRow = {
    body: string;
    is_true: boolean | null;
    correct_option_label: string | null;
    marks_fraction: number | null;
    feedback: string | null;
    sort_order: number;
    settings: SettingMap | null;
};

export type AnswerRow = {
    item_index: number | null;
    match_mode: 'exact' | 'contains' | 'regex' | 'numeric';
    answer_text: string | null;
    case_sensitive: boolean;
    numeric_value: number | null;
    tolerance: number | null;
    tolerance_type: 'absolute' | 'relative';
    unit: string | null;
    marks_fraction: number;
    feedback: string | null;
    sort_order: number;
};

export type RubricRow = {
    criterion: string;
    max_marks: number;
    guidance: string | null;
    sort_order: number;
};

export type ReferenceRow = {
    kind: 'book' | 'journal' | 'guideline' | 'url' | 'other';
    citation: string;
    locator: string | null;
    url: string | null;
    sort_order: number;
};

export type QuestionDraft = {
    question_type_id: number | null;
    course_id: number | null;
    node_id: number | null;
    discipline_id: number | null;
    vignette: string | null;
    stem: string;
    lead_in: string | null;
    explanation: string | null;
    settings: SettingMap;
    marks: number;
    negative_marks: number;
    cognitive_level_id: number | null;
    difficulty_level_id: number | null;
    options: OptionRow[];
    items: ItemRow[];
    answers: AnswerRow[];
    rubric: RubricRow[];
    references: ReferenceRow[];
    tag_ids: number[];
};

export type StoredVersion = {
    id: number;
    questionId: number;
    versionNo: number;
    status: string;
    statusLabel: string;
    editable: boolean;
    questionTypeId: number;
    courseId: number;
    nodeId: number;
    disciplineId: number | null;
    vignette: string | null;
    stem: string;
    leadIn: string | null;
    explanation: string | null;
    settings: SettingMap;
    marks: number;
    negativeMarks: number;
    cognitiveLevelId: number | null;
    difficultyLevelId: number | null;
    options: OptionRow[];
    items: ItemRow[];
    answers: AnswerRow[];
    rubric: RubricRow[];
    references: ReferenceRow[];
    tagIds: number[];
    authorId: number;
};

export type QuestionChecks = {
    errors: Record<string, string[]>;
    warnings: string[];
};

export type QuestionListRow = {
    id: number;
    reference: string;
    versionId: number;
    versionNo: number;
    status: string;
    statusLabel: string;
    type: string;
    course: string;
    marks: number;
    author: string;
    isMine: boolean;
    isArchived: boolean;
    timesUsed: number;
    summary: string;
    updatedAt: string | null;
};

export type ImportSummary = {
    id: number;
    name: string;
    format: string;
    status: string;
    rowsTotal: number;
    rowsValid: number;
    rowsInvalid: number;
    rowsCommitted: number;
    uploadedBy?: string | null;
    uploadedAt: string | null;
    committable?: boolean;
    committedAt?: string | null;
};

export type ImportRowParsed = {
    type: string;
    course_id: number;
    node_id: number;
    topic: string | null;
    stem: string;
    marks: number;
    options: number;
    correct: number;
    items: number;
    answers: number;
    references: number;
    tags: number;
};

export type ImportRowView = {
    id: number;
    rowNumber: number;
    status: string;
    raw: Record<string, string> | null;
    parsed: ImportRowParsed | null;
    errors: string[];
    warnings: string[];
    questionId: number | null;
    versionId: number | null;
};
