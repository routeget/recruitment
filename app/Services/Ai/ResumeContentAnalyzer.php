<?php

declare(strict_types=1);

namespace App\Services\Ai;

class ResumeContentAnalyzer
{
    /**
     * Known technical & professional skill terms matched ONLY when literally present in the document text.
     *
     * @var array<int, string>
     */
    private const KNOWN_SKILL_TERMS = [
        'PHP 8.5', 'PHP', 'Laravel 13', 'Laravel', 'Symfony', 'Python', 'TypeScript', 'JavaScript',
        'React', 'Vue.js', 'Node.js', 'Go', 'Golang', 'Java', 'C#', '.NET', 'Rust',
        'MariaDB', 'MySQL', 'PostgreSQL', 'SQL', 'Redis', 'MongoDB', 'Elasticsearch',
        'Docker', 'Kubernetes', 'AWS', 'Azure', 'GCP', 'Terraform', 'CI/CD', 'GitHub Actions',
        'REST API', 'GraphQL', 'gRPC', 'Microservices', 'Distributed Systems', 'System Architecture',
        'OAuth2', 'OIDC', 'SAML', 'Microsoft Entra ID', 'RBAC', 'ABAC', 'Zero Trust',
        'Dynamics 365', 'ERP Integration', 'HL7', 'FHIR', 'Clinical Informatics',
        'Machine Learning', 'AI Governance', 'Data Engineering', 'Project Management', 'Agile', 'Scrum',
    ];

    /**
     * Detect and neutralize prompt-injection / instruction-override attempts inside untrusted resume text.
     *
     * @return array{clean_text: string, detected: bool, flags: array<int, string>}
     */
    public function detectAndNeutralizePromptInjection(string $rawText): array
    {
        $patterns = [
            'ignore_instructions' => '/\b(ignore|disregard|forget)\s+(all\s+)?(previous|prior|above|system)\s+(instructions|prompts|rules|directives)\b/i',
            'system_override' => '/\b(system\s*prompt\s*:|you\s+are\s+now\s+a|act\s+as\s+system|new\s+system\s+instructions)\b/i',
            'privilege_or_decision_override' => '/\b(override\s+security|grant\s+admin|approve\s+hiring\s+decision|set\s+score\s+to\s+100|bypass\s+hr\s+handover|auto[\s\-]?approve|mark\s+candidate\s+as\s+hired)\b/i',
            'delimiter_escape' => '/(<\/?system>|<\/?instruction>|\[SYSTEM\s*OVERRIDE\])/i',
        ];

        $flags = [];
        $lines = preg_split('/\r\n|\r|\n/', $rawText) ?: [];
        $cleanLines = [];

        foreach ($lines as $line) {
            $lineFlagged = false;
            foreach ($patterns as $flagKey => $regex) {
                if (preg_match($regex, $line)) {
                    $flags[] = "Prompt injection pattern [{$flagKey}] neutralized: ".substr(trim($line), 0, 80);
                    $lineFlagged = true;
                }
            }

            if (! $lineFlagged) {
                $cleanLines[] = $line;
            }
        }

        return [
            'clean_text' => implode("\n", $cleanLines),
            'detected' => ! empty($flags),
            'flags' => array_values(array_unique($flags)),
        ];
    }

    /**
     * Extract structured resume fields strictly from document text without inventing missing information.
     *
     * @return array<string, mixed>
     */
    public function extractStructuredResume(string $rawText): array
    {
        $injection = $this->detectAndNeutralizePromptInjection($rawText);
        $text = trim($injection['clean_text']);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: []), fn ($l) => $l !== ''));

        $fullName = $this->extractFullName($text, $lines);
        $firstName = null;
        $lastName = null;
        if ($fullName !== null) {
            $parts = preg_split('/\s+/', $fullName, 2) ?: [];
            $firstName = $parts[0] ?? null;
            $lastName = $parts[1] ?? null;
        }

        $email = $this->extractEmail($text);
        $phone = $this->extractPhone($text);
        [$city, $countryCode] = $this->extractLocation($text);
        $links = $this->extractLinks($text);
        $summary = $this->extractSectionParagraph($text, ['Professional Summary', 'Summary', 'Executive Summary', 'Profile', 'Objective']);
        $currentTitle = $this->extractCurrentTitle($text);
        [$yearsExp, $yearsAmbiguous] = $this->extractYearsExperience($text);
        [$workExperience, $workAmbiguous] = $this->extractWorkExperience($text);

        if ($currentTitle === null && ! empty($workExperience[0]['job_title'])) {
            $currentTitle = $workExperience[0]['job_title'];
        }

        $education = $this->extractEducation($text);
        $skills = $this->extractSkills($text);
        $certifications = $this->extractListSection($text, ['Certifications', 'Licenses & Certifications', 'Licenses']);
        $projects = $this->extractProjects($text);
        $languages = $this->extractListSection($text, ['Languages', 'Spoken Languages']);

        $missingFields = [];
        if ($fullName === null) {
            $missingFields[] = 'full_name';
        }
        if ($email === null) {
            $missingFields[] = 'contact.email';
        }
        if ($phone === null) {
            $missingFields[] = 'contact.phone';
        }
        if ($summary === null) {
            $missingFields[] = 'professional_summary';
        }
        if ($currentTitle === null) {
            $missingFields[] = 'current_title';
        }
        if ($yearsExp === null) {
            $missingFields[] = 'years_experience';
        }
        if (empty($workExperience)) {
            $missingFields[] = 'work_experience';
        }
        if (empty($education)) {
            $missingFields[] = 'education';
        }
        if (empty($skills)) {
            $missingFields[] = 'skills';
        }
        if (empty($certifications)) {
            $missingFields[] = 'certifications';
        }
        if (empty($languages)) {
            $missingFields[] = 'languages';
        }

        $ambiguousFields = array_values(array_unique(array_merge($yearsAmbiguous, $workAmbiguous)));

        $evidenceQuotes = array_slice($lines, 0, 4);

        return [
            'schema_version' => 'RESUME_SCHEMA_v1.0',
            'full_name' => $fullName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'contact' => [
                'email' => $email,
                'phone' => $phone,
                'location_city' => $city,
                'country_code' => $countryCode,
                'links' => $links,
            ],
            'professional_summary' => $summary,
            'current_title' => $currentTitle,
            'years_experience' => $yearsExp,
            'skills' => $skills,
            'work_experience' => $workExperience,
            'education' => $education,
            'certifications' => $certifications,
            'projects' => $projects,
            'languages' => $languages,
            'professional_links' => $links,
            'missing_fields' => $missingFields,
            'ambiguous_fields' => $ambiguousFields,
            'evidence_quotes' => $evidenceQuotes,
            // Legacy compatibility keys for `resume_extraction`
            'extracted_skills' => $skills,
            'years_experience_estimate' => $yearsExp,
            'education_highlights' => array_values(array_map(
                fn (array $ed): string => trim(($ed['qualification'] ?? '').' '.($ed['field_of_study'] ?? '').' — '.($ed['institution'] ?? ''), ' —'),
                $education
            )),
            'prompt_injection_detected' => $injection['detected'],
            'security_flags' => $injection['flags'],
        ];
    }

    /**
     * Compare candidate extracted resume facts against job requirements with clear separation of facts vs interpretation.
     *
     * @param  array<string, mixed>  $resumeData
     * @param  array<string, mixed>  $jobRequirements
     * @return array<string, mixed>
     */
    public function evaluateCandidateMatch(array $resumeData, array $jobRequirements, string $rawContext = ''): array
    {
        $candidateSkills = array_values(array_filter(array_map('strval', $resumeData['skills'] ?? $resumeData['extracted_skills'] ?? [])));
        if (empty($candidateSkills) && $rawContext !== '') {
            $candidateSkills = $this->extractSkills($rawContext);
        }

        $requiredSkills = array_values(array_filter(array_map('strval', $jobRequirements['skills_required'] ?? [])));
        $matchedSkills = [];
        $missingSkills = [];

        foreach ($requiredSkills as $reqSkill) {
            $found = false;
            foreach ($candidateSkills as $candSkill) {
                if (strcasecmp($reqSkill, $candSkill) === 0 || stripos($candSkill, $reqSkill) !== false || stripos($reqSkill, $candSkill) !== false) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                $matchedSkills[] = $reqSkill;
            } else {
                $missingSkills[] = $reqSkill;
            }
        }

        $skillRatio = count($requiredSkills) > 0
            ? count($matchedSkills) / count($requiredSkills)
            : (count($candidateSkills) > 0 ? 0.85 : 0.50);

        $yearsExp = $resumeData['years_experience'] ?? $resumeData['years_experience_estimate'] ?? null;
        if ($yearsExp === null && $rawContext !== '') {
            [$yearsExp] = $this->extractYearsExperience($rawContext);
        }

        $expScore = $yearsExp !== null ? min(100, max(45, 55 + ((int) $yearsExp * 5))) : 60;
        $skillScore = (int) round(min(100, max(35, $skillRatio * 100)));
        $overallScore = round(($skillScore * 0.65) + ($expScore * 0.35), 1);

        $missingFlags = [];
        foreach ($missingSkills as $ms) {
            $missingFlags[] = "Required skill '{$ms}' was not explicitly evidenced in the resume; verify during structured interview.";
        }
        if ($yearsExp === null) {
            $missingFlags[] = 'Total years of relevant experience is not explicitly stated in the resume; clarify employment timeline.';
        }
        if (! empty($resumeData['ambiguous_fields'])) {
            foreach ((array) $resumeData['ambiguous_fields'] as $amb) {
                $missingFlags[] = (string) $amb;
            }
        }

        $advisory = $overallScore >= 75.0
            ? 'ADVISORY_PROCEED_TO_HUMAN_INTERVIEW'
            : ($overallScore >= 55.0 ? 'ADVISORY_HUMAN_SCREENING_REVIEW_REQUIRED' : 'ADVISORY_SIGNIFICANT_REQUIREMENT_GAPS_IDENTIFIED');

        return [
            'extracted_facts' => [
                'verified_skills_in_document' => $candidateSkills,
                'stated_years_experience' => $yearsExp,
                'current_title' => $resumeData['current_title'] ?? null,
                'education_entries' => $resumeData['education'] ?? [],
                'work_experience_count' => count((array) ($resumeData['work_experience'] ?? [])),
            ],
            'ai_interpretation' => [
                'matched_required_skills' => $matchedSkills,
                'unverified_or_missing_skills' => $missingSkills,
                'rationale' => count($matchedSkills) > 0
                    ? 'Candidate resume provides direct textual evidence for '.implode(', ', $matchedSkills).'.'
                    : 'Limited direct overlap found between resume keywords and required job skills; manual review recommended.',
                'advisory_notice' => 'AI matching output is strictly advisory. Deterministic eligibility rules and human recruiter judgment remain authoritative.',
            ],
            'criteria_scores' => [
                [
                    'criterion' => 'Required Skills Alignment',
                    'score' => $skillScore,
                    'evidence' => ! empty($matchedSkills)
                        ? 'Matched skills in resume: '.implode(', ', $matchedSkills)
                        : (! empty($candidateSkills) ? 'Extracted skills: '.implode(', ', $candidateSkills) : 'No explicit technical skill list extracted.'),
                ],
                [
                    'criterion' => 'Experience & Role Progression',
                    'score' => $expScore,
                    'evidence' => $yearsExp !== null
                        ? "Document states {$yearsExp} years of experience."
                        : 'Years of experience not explicitly stated in document.',
                ],
            ],
            'overall_score' => $overallScore,
            'recommendation_advisory' => $advisory,
            'missing_evidence_flags' => $missingFlags ?: ['Verify depth of production architecture experience during structured panel interview.'],
            'protected_traits_inferred' => false,
            'human_review_required' => true,
        ];
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function extractFullName(string $text, array $lines): ?string
    {
        if (preg_match('/(?:Full\s+Name|Candidate\s+Name|Name)\s*:\s*([A-Z][a-zA-Z\'\-]+(?:\s+[A-Z][a-zA-Z\'\-]+){1,3})/m', $text, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/Candidate\s+([A-Z][a-zA-Z\'\-]+\s+[A-Z][a-zA-Z\'\-]+)/', $text, $m)) {
            return trim($m[1]);
        }

        if (! empty($lines[0])) {
            $first = trim($lines[0], "#* \t");
            if (
                preg_match('/^[A-Z][a-zA-Z\'\-]+(?:\s+[A-Z][a-zA-Z\.\'\-]+){1,3}$/', $first)
                && ! preg_match('/\b(Resume|Curriculum|Vitae|Summary|Experience|Education|Skills|Engineer|Architect|Developer|Manager)\b/i', $first)
            ) {
                return $first;
            }
        }

        return null;
    }

    private function extractEmail(string $text): ?string
    {
        if (preg_match('/([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/', $text, $m)) {
            return strtolower(trim($m[1]));
        }

        return null;
    }

    private function extractPhone(string $text): ?string
    {
        if (preg_match('/(?:Phone|Tel|Mobile)\s*:\s*([+\d][\d\s().\-]{6,22}\d)/i', $text, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/(\+\d{1,3}[\s\-]?\(?\d{2,4}\)?[\s\-]?\d{3,4}[\s\-]?\d{3,4})/', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function extractLocation(string $text): array
    {
        $city = null;
        $country = null;

        if (preg_match('/(?:Location|City|Address)\s*:\s*([A-Za-z\s.\-]+?)(?:,\s*([A-Z]{2}))?(?:\r|\n|$|\|)/i', $text, $m)) {
            $city = trim($m[1]);
            $country = ! empty($m[2]) ? strtoupper(trim($m[2])) : null;
        }

        return [$city ?: null, $country ?: null];
    }

    /**
     * @return array<int, string>
     */
    private function extractLinks(string $text): array
    {
        if (preg_match_all('/https?:\/\/[^\s,)>"]+/i', $text, $matches)) {
            return array_values(array_unique(array_map(fn ($u) => rtrim($u, '.'), $matches[0])));
        }

        return [];
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function extractSectionParagraph(string $text, array $headers): ?string
    {
        foreach ($headers as $header) {
            $quoted = preg_quote($header, '/');
            if (preg_match('/(?:^|\n)\s*#*\s*'.$quoted.'\s*:?\s*\n+(.+?)(?=\n\s*#*\s*(?:Experience|Work\s+Experience|Employment|Education|Skills|Technical\s+Skills|Certifications|Projects|Languages)\b|\z)/is', $text, $m)) {
                $cleaned = trim(preg_replace('/\s+/', ' ', $m[1]) ?? '');
                if ($cleaned !== '') {
                    return substr($cleaned, 0, 1000);
                }
            }
            if (preg_match('/(?:^|\n)\s*'.$quoted.'\s*:\s*(.+)(?:\r|\n|$)/i', $text, $m)) {
                $cleaned = trim($m[1]);
                if ($cleaned !== '') {
                    return substr($cleaned, 0, 1000);
                }
            }
        }

        return null;
    }

    private function extractCurrentTitle(string $text): ?string
    {
        if (preg_match('/(?:Current\s+Title|Current\s+Role|Job\s+Title|Title)\s*:\s*([^\r\n|]+)/i', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * @return array{0: ?int, 1: array<int, string>}
     */
    private function extractYearsExperience(string $text): array
    {
        $ambiguous = [];
        if (preg_match('/(\d{1,2})\+?\s*years?\s+of\s+(?:[a-zA-Z\s\-]+)?experience/i', $text, $m)) {
            return [(int) $m[1], []];
        }

        if (preg_match('/(?:Years\s+of\s+Experience|Experience\s+Years)\s*:\s*(\d{1,2})/i', $text, $m)) {
            return [(int) $m[1], []];
        }

        if (preg_match('/\b(several|multiple|many)\s+years\s+of\s+experience\b/i', $text)) {
            $ambiguous[] = 'Years of experience stated qualitatively ("several/multiple years") without an exact numeric value.';
        }

        return [null, $ambiguous];
    }

    /**
     * @return array{0: array<int, array<string, ?string>>, 1: array<int, string>}
     */
    private function extractWorkExperience(string $text): array
    {
        $entries = [];
        $ambiguous = [];

        // Pattern 1: "Job Title at Employer (2020 - Present)" or "Job Title | Employer | 2019 - 2023"
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        foreach ($lines as $line) {
            $trimmed = ltrim(trim($line), '-*• ');
            if (preg_match('/^([A-Za-z0-9\s\/,&.\-]+?)\s+(?:at|@|—|-)\s+([A-Za-z0-9\s.,&\'\-]+?)\s*\((\d{4}(?:-\d{2})?|\?)\s*(?:–|-|to)\s*(Present|Current|\d{4}(?:-\d{2})?|\?)\)/i', $trimmed, $m)) {
                $start = $m[3] === '?' ? null : trim($m[3]);
                $end = $m[4] === '?' ? null : trim($m[4]);
                if ($start === null || $end === null) {
                    $ambiguous[] = "Work experience entry '{$m[1]} at {$m[2]}' has incomplete or ambiguous employment dates.";
                }
                $entries[] = [
                    'job_title' => trim($m[1]),
                    'employer' => trim($m[2]),
                    'start_date' => $start,
                    'end_date' => $end,
                    'summary' => null,
                ];
            }
        }

        return [$entries, $ambiguous];
    }

    /**
     * @return array<int, array<string, ?string>>
     */
    private function extractEducation(string $text): array
    {
        $education = [];
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        foreach ($lines as $line) {
            $trimmed = ltrim(trim($line), '-*• ');
            if (preg_match('/\b(B\.?S\.?|M\.?S\.?|B\.?A\.?|M\.?B\.?A\.?|Ph\.?D\.?|Bachelor(?:\s+of\s+[A-Za-z]+)?|Master(?:\s+of\s+[A-Za-z]+)?)\b/i', $trimmed, $m)) {
                $qual = trim($m[1]);
                $institution = null;
                $field = null;
                $year = null;

                if (preg_match('/\b(?:in|of)\s+([A-Za-z\s&]+?)(?:,|—|-|\bfrom\b|\bat\b|\(|\d{4}|$)/i', $trimmed, $fm)) {
                    $field = trim($fm[1]);
                }
                if (preg_match('/\b([A-Z][A-Za-z\s&.\']+(?:University|College|Institute|School|Academy)[A-Za-z\s]*)/', $trimmed, $im)) {
                    $institution = trim($im[1]);
                }
                if (preg_match('/\b(19\d{2}|20\d{2})\b/', $trimmed, $ym)) {
                    $year = $ym[1];
                }

                $education[] = [
                    'qualification' => $qual,
                    'field_of_study' => $field,
                    'institution' => $institution,
                    'graduation_year' => $year,
                ];
            }
        }

        return $education;
    }

    /**
     * @return array<int, string>
     */
    private function extractSkills(string $text): array
    {
        $found = [];

        if (preg_match('/(?:Technical\s+Skills|Key\s+Skills|Core\s+Skills|Skills|Competencies)\s*:\s*([^\r\n]+)/i', $text, $m)) {
            $parts = preg_split('/[,;|•]+/', $m[1]) ?: [];
            foreach ($parts as $part) {
                $clean = trim($part, " \t.-*");
                if ($clean !== '' && strlen($clean) <= 50) {
                    $found[] = $clean;
                }
            }
        }

        foreach (self::KNOWN_SKILL_TERMS as $term) {
            $quoted = preg_quote($term, '/');
            if (preg_match('/(?<![a-zA-Z0-9])'.$quoted.'(?![a-zA-Z0-9])/i', $text)) {
                $alreadyPresent = false;
                foreach ($found as $existing) {
                    if (strcasecmp($existing, $term) === 0) {
                        $alreadyPresent = true;
                        break;
                    }
                }
                if (! $alreadyPresent) {
                    $found[] = $term;
                }
            }
        }

        return array_values($found);
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<int, string>
     */
    private function extractListSection(string $text, array $headers): array
    {
        foreach ($headers as $header) {
            $quoted = preg_quote($header, '/');
            if (preg_match('/(?:^|\n)\s*'.$quoted.'\s*:\s*([^\r\n]+)/i', $text, $m)) {
                $items = array_filter(array_map('trim', preg_split('/[,;|•]+/', $m[1]) ?: []));
                if (! empty($items)) {
                    return array_values($items);
                }
            }
        }

        return [];
    }

    /**
     * @return array<int, array{name: string, description: ?string}>
     */
    private function extractProjects(string $text): array
    {
        if (preg_match('/(?:^|\n)\s*(?:Projects|Key\s+Projects)\s*:\s*([^\r\n]+)/i', $text, $m)) {
            $items = array_filter(array_map('trim', preg_split('/[;|]+/', $m[1]) ?: []));
            $projects = [];
            foreach ($items as $item) {
                $projects[] = ['name' => $item, 'description' => null];
            }

            return $projects;
        }

        return [];
    }

    /**
     * Redact direct contact identifiers (emails, phone numbers, personal URLs, SSNs/IDs) from text before external AI transmission.
     */
    public function redactDirectContactIdentifiers(string $text): string
    {
        $redacted = (string) preg_replace('/\b(sk-[A-Za-z0-9_-]{8,}|Bearer\s+[A-Za-z0-9._-]{8,})\b/i', '[REDACTED_SECRET]', $text);
        $redacted = (string) preg_replace('/\b\d{3}-\d{2}-\d{4}\b/', '[REDACTED_SSN]', $redacted);
        $redacted = (string) preg_replace('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', '[REDACTED_EMAIL]', $redacted);
        $redacted = (string) preg_replace('/https?:\/\/[^\s,)>"]+/i', '[REDACTED_URL]', $redacted);
        $redacted = (string) preg_replace('/\b(?:www\.|linkedin\.com\/in\/|github\.com\/)[^\s,)>"]+/i', '[REDACTED_URL]', $redacted);
        $redacted = (string) preg_replace('/(?:Phone|Tel|Mobile|Cell)\s*:\s*\+?\d[\d\s().\-]{6,20}\d/i', '[REDACTED_PHONE]', $redacted);
        $redacted = (string) preg_replace('/(?<![A-Za-z0-9_])\+?\d{1,3}[\s.\-]?\(?\d{2,4}\)?[\s.\-]\d{3,4}[\s.\-]\d{3,4}\b/', '[REDACTED_PHONE]', $redacted);
        $redacted = (string) preg_replace('/(?<![A-Za-z0-9_])\+\d{1,3}[\s.\-]?\d{4,6}[\s.\-]?\d{4,6}\b/', '[REDACTED_PHONE]', $redacted);
        $redacted = (string) preg_replace('/\b(?:\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}\b/', '[REDACTED_PHONE]', $redacted);

        return $redacted;
    }

    /**
     * Build a minimized, contact-redacted resume representation for external AI providers.
     * Direct contact identifiers (email, phone, personal URLs, government IDs, and raw contact header lines) are omitted/redacted.
     *
     * @param  array<string, mixed>|null  $localBaseline
     */
    public function buildMinimizedRedactedExternalResumePrompt(string $cleanText, ?array $localBaseline = null): string
    {
        $baseline = $localBaseline ?? $this->extractStructuredResume($cleanText);
        $redactedBody = $this->redactDirectContactIdentifiers($cleanText);

        $sections = [
            '<minimized_candidate_qualifications>',
            'Notice: Direct contact identifiers (email, phone, personal URLs, government IDs) have been redacted locally for privacy minimization.',
        ];

        if (! empty($baseline['current_title'])) {
            $sections[] = 'Stated Role / Title: '.$this->redactDirectContactIdentifiers((string) $baseline['current_title']);
        }
        if (isset($baseline['years_experience']) && $baseline['years_experience'] !== null) {
            $sections[] = 'Stated Years of Experience: '.(int) $baseline['years_experience'];
        }
        if (! empty($baseline['professional_summary'])) {
            $sections[] = 'Professional Summary: '.$this->redactDirectContactIdentifiers((string) $baseline['professional_summary']);
        }
        if (! empty($baseline['skills']) && is_array($baseline['skills'])) {
            $sections[] = 'Locally Identified Skills: '.implode(', ', array_map('strval', $baseline['skills']));
        }
        if (! empty($baseline['work_experience']) && is_array($baseline['work_experience'])) {
            $expLines = [];
            foreach ($baseline['work_experience'] as $exp) {
                if (is_array($exp)) {
                    $title = $this->redactDirectContactIdentifiers((string) ($exp['job_title'] ?? ''));
                    $employer = $this->redactDirectContactIdentifiers((string) ($exp['employer'] ?? ''));
                    $start = (string) ($exp['start_date'] ?? '?');
                    $end = (string) ($exp['end_date'] ?? 'Present');
                    $expLines[] = trim("{$title} at {$employer} ({$start} - {$end})");
                }
            }
            if (! empty($expLines)) {
                $sections[] = "Work Experience:\n- ".implode("\n- ", $expLines);
            }
        }
        if (! empty($baseline['education']) && is_array($baseline['education'])) {
            $eduLines = [];
            foreach ($baseline['education'] as $edu) {
                if (is_array($edu)) {
                    $qual = $this->redactDirectContactIdentifiers((string) ($edu['qualification'] ?? ''));
                    $field = $this->redactDirectContactIdentifiers((string) ($edu['field_of_study'] ?? ''));
                    $inst = $this->redactDirectContactIdentifiers((string) ($edu['institution'] ?? ''));
                    $year = (string) ($edu['graduation_year'] ?? '');
                    $eduLines[] = trim("{$qual} {$field} - {$inst} {$year}");
                }
            }
            if (! empty($eduLines)) {
                $sections[] = "Education:\n- ".implode("\n- ", $eduLines);
            }
        }
        if (! empty($baseline['certifications']) && is_array($baseline['certifications'])) {
            $sections[] = 'Certifications: '.implode(', ', array_map('strval', $baseline['certifications']));
        }
        if (! empty($baseline['languages']) && is_array($baseline['languages'])) {
            $sections[] = 'Languages: '.implode(', ', array_map('strval', $baseline['languages']));
        }

        // Include contact-redacted body lines that do not consist purely of redacted contact tokens
        $filteredBodyLines = [];
        foreach (preg_split('/\r\n|\r|\n/', $redactedBody) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $withoutTokens = trim((string) preg_replace('/\[REDACTED_(?:EMAIL|PHONE|URL|SSN|SECRET)\]|[|,\-\s•]+/', '', $trimmed));
            if ($withoutTokens !== '') {
                $filteredBodyLines[] = $trimmed;
            }
        }

        if (! empty($filteredBodyLines)) {
            $sections[] = "Contact-Redacted Document Excerpt:\n".implode("\n", array_slice($filteredBodyLines, 0, 40));
        }

        $sections[] = '</minimized_candidate_qualifications>';

        return implode("\n", $sections);
    }

    /**
     * Sanitize structured resume data before passing it to candidate matching so direct contact identifiers are excluded.
     *
     * @param  array<string, mixed>  $resumeData
     * @return array<string, mixed>
     */
    public function sanitizeResumeDataForExternalMatch(array $resumeData): array
    {
        return [
            'current_title' => isset($resumeData['current_title']) ? $this->redactDirectContactIdentifiers((string) $resumeData['current_title']) : null,
            'years_experience' => $resumeData['years_experience'] ?? ($resumeData['total_years_experience'] ?? null),
            'skills' => is_array($resumeData['skills'] ?? null) ? array_values($resumeData['skills']) : [],
            'work_experience' => is_array($resumeData['work_experience'] ?? null) ? array_values($resumeData['work_experience']) : [],
            'education' => is_array($resumeData['education'] ?? null) ? array_values($resumeData['education']) : [],
            'certifications' => is_array($resumeData['certifications'] ?? null) ? array_values($resumeData['certifications']) : [],
            'languages' => is_array($resumeData['languages'] ?? null) ? array_values($resumeData['languages']) : [],
            'ambiguous_fields' => is_array($resumeData['ambiguous_fields'] ?? null) ? array_values($resumeData['ambiguous_fields']) : [],
        ];
    }
}
