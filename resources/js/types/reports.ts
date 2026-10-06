export type CohortDescription = {
    programmeId: number;
    professionalId: number;
    intakeId: number;
    termId: number | null;
    programme: string | null;
    year: string | null;
    intake: string | null;
};

export type SheetCourse = {
    examinationId: number;
    reference: string;
    code: string;
    title: string;
    totalMarks: number;
    passPercentage: number;
    creditHours: number | null;
    /** The parts this subject's result is made of; empty where it is the paper alone. */
    components: {
        code: string;
        name: string;
        maxMarks: number;
        group: 'theory' | 'practical';
    }[];
};

export type SheetCourseResult = {
    totalMarks: number | null;
    percentage: number | null;
    grade: string | null;
    gradePoint: number | null;
    isPass: boolean | null;
    pending: boolean;
    /** What each part gave this candidate; empty where the subject is the paper alone. */
    parts: CourseComponentPart[];
    /** 'theory' or 'practical' where that half was failed on its own. */
    failedGroups: string[];
};

export type SheetCandidate = {
    candidateNo: string;
    name: string;
    rollNo: string | null;
    identityClash: boolean;
    courses: Record<number, SheetCourseResult>;
    obtainedMarks: number;
    possibleMarks: number;
    percentage: number | null;
    gpa: number | null;
    creditHours: number | null;
    satEverything: boolean;
    isPass: boolean;
    position: number | null;
};

export type TabulationSheetData = {
    calendarType: string | null;
    courses: SheetCourse[];
    candidates: SheetCandidate[];
    awaiting: { reference: string; title: string }[];
    creditHoursMissing: string[];
};

export type CandidateStatementData = {
    candidate: SheetCandidate;
    courses: SheetCourse[];
    calendarType: string | null;
    awaiting: { reference: string; title: string }[];
    creditHoursMissing: string[];
    cumulative: {
        cgpa: number | null;
        creditHours: number;
        terms: {
            termId: number | null;
            name: string | null;
            gpa: number | null;
            creditHours: number | null;
        }[];
    } | null;
    place: {
        programme: string | null;
        year: string | null;
        intake: string | null;
    };
};

export type ResultComponentRow = {
    id: number;
    code: string;
    name: string;
    maxMarks: number;
    group: 'theory' | 'practical';
    minPassPercentage: number | null;
    /** This system's own paper: shown, but never typed in. */
    isPaper: boolean;
};

export type ComponentCandidate = {
    id: number;
    candidateNo: string;
    name: string;
};

/** One part of a subject as it stands for one candidate; null marks are not yet entered. */
export type CourseComponentPart = {
    code: string;
    name: string;
    marks: number | null;
    maxMarks: number;
};
