<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AI\AiService;
use App\Models\LearningRoadmap;
use App\Models\RoadmapStep;
use App\Models\Career;
use App\Models\UserSkill;
use App\Models\CareerRecommendation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Exception;

class AiController extends Controller
{
    protected AiService $aiService;

    public function __construct(AiService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * POST /ai/recommend-career or /api/v1/ai/recommend-career
     */
    public function recommendCareer(Request $request): JsonResponse
    {
        $userId = Auth::id();

        // Support flexible inputs: skills (array or comma-separated string), career_id, bio, education
        $rawSkills = $request->input('skills');
        if (is_string($rawSkills)) {
            $skills = array_filter(array_map('trim', explode(',', $rawSkills)));
        } elseif (is_array($rawSkills)) {
            $skills = $rawSkills;
        } else {
            // Fallback to saved user_skills if available
            $skills = $userId
                ? UserSkill::where('user_id', $userId)->join('skills', 'user_skills.skill_id', '=', 'skills.id')->pluck('skills.name')->toArray()
                : [];
        }

        $payload = [
            'skills'     => $skills,
            'bio'        => $request->input('bio', 'Tech enthusiast looking for career development'),
            'education'  => $request->input('education', 'Software Development / Computer Science'),
            'career_id'  => $request->input('career_id'),
        ];

        try {
            $response = $this->aiService->getCareerRecommendation($payload);
            if (!$response) {
                throw new Exception('Failed to generate career recommendation from AI service.');
            }
            return response()->json([
                'success' => true,
                'data'    => $response,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /ai/skill-gap or /ai/analyze-skills
     */
    public function analyzeSkills(Request $request): JsonResponse
    {
        $userId = Auth::id();

        $careerTitle = $request->input('career_title') ?? $request->input('target_role');
        if (!$careerTitle && $userId) {
            $latest = CareerRecommendation::where('user_id', $userId)->latest()->first();
            $careerTitle = $latest ? $latest->recommended_career : 'Full Stack Developer';
        }
        $careerTitle = $careerTitle ?: 'Full Stack Developer';

        $rawSkills = $request->input('current_skills') ?? $request->input('skills');
        if (is_string($rawSkills)) {
            $currentSkills = array_filter(array_map('trim', explode(',', $rawSkills)));
        } elseif (is_array($rawSkills)) {
            $currentSkills = $rawSkills;
        } else {
            $currentSkills = $userId
                ? UserSkill::where('user_id', $userId)->join('skills', 'user_skills.skill_id', '=', 'skills.id')->pluck('skills.name')->toArray()
                : ['HTML', 'CSS', 'JavaScript'];
        }

        try {
            $response = $this->aiService->getSkillGapAnalysis($careerTitle, $currentSkills);
            if (!$response) {
                throw new Exception('Failed to analyze skill gaps from AI service.');
            }
            return response()->json([
                'success' => true,
                'data'    => $response,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /roadmaps/generate or /ai/generate-roadmap
     */
     public function generateRoadmap(Request $request): JsonResponse
     {
         $userId = Auth::id() ?? 1;

         $careerTitle = $request->input('career_title') ?? $request->input('target_role') ?? 'Software Engineer';
         $rawMissing = $request->input('missing_skills') ?? $request->input('skills') ?? ['Advanced Backend Architecture', 'System Design'];

         if (is_string($rawMissing)) {
             $missingSkills = array_filter(array_map('trim', explode(',', $rawMissing)));
         } else {
             $missingSkills = (array) $rawMissing;
         }

         try {
             $response = $this->aiService->generateRoadmap($careerTitle, $missingSkills);
             if (!$response) {
                 throw new Exception('Failed to generate roadmap from AI service.');
             }

             // Persist roadmap and steps to database so they appear in Progress & Roadmap views
             $roadmap = $this->persistRoadmap($userId, $careerTitle, $response);

             return response()->json([
                 'success' => true,
                 'data'    => $response,
                 'roadmap' => $roadmap->load('steps'),
             ]);
         } catch (Exception $e) {
             return response()->json([
                 'success' => false,
                 'message' => $e->getMessage(),
             ], 500);
         }
     }

    /**
     * POST /roadmaps/{id}/regenerate
     */
    public function regenerateRoadmap(Request $request, $id): JsonResponse
    {
        $userId = Auth::id() ?? 1;
        $roadmap = LearningRoadmap::where('user_id', $userId)->find($id);

        $careerTitle = $roadmap ? $roadmap->title : 'Software Engineer';
        $missingSkills = ['Advanced Best Practices', 'Optimization'];

        try {
            $response = $this->aiService->generateRoadmap($careerTitle, $missingSkills);
            if (!$response) {
                throw new Exception('Failed to regenerate roadmap.');
            }

            if ($roadmap) {
                $roadmap->update([
                    'ai_response'        => $response,
                    'estimated_duration' => $response['estimated_duration'] ?? $roadmap->estimated_duration,
                    'version'            => $roadmap->version + 1,
                ]);

                // Replace steps with the regenerated phases
                $roadmap->steps()->delete();
                $items = $response['phases'] ?? $response['steps'] ?? [];
                if (!empty($items) && is_array($items)) {
                    $seq = 1;
                    foreach ($items as $item) {
                        $title = $item['title'] ?? $item['phase_name'] ?? $item['name'] ?? ("Phase " . $seq);
                        $description = $item['description'] ?? '';
                        if (!empty($item['skills_covered']) && is_array($item['skills_covered'])) {
                            $skillsStr = implode(', ', $item['skills_covered']);
                            $description = $description ? ($description . " | Skills: " . $skillsStr) : ("Skills: " . $skillsStr);
                        }
                        $hours = isset($item['estimated_hours']) ? intval($item['estimated_hours']) : 20;

                        RoadmapStep::create([
                            'roadmap_id'        => $roadmap->id,
                            'title'             => $title,
                            'description'       => $description,
                            'sequence'          => $seq++,
                            'estimated_hours'   => $hours,
                            'completion_status' => 'pending',
                        ]);
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data'    => $response,
                'roadmap' => $roadmap ? $roadmap->fresh('steps') : null,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper to persist an AI-generated roadmap and its steps.
     */
    protected function persistRoadmap(int $userId, string $careerTitle, array $response): LearningRoadmap
    {
        $career = Career::where('title', 'like', "%{$careerTitle}%")
            ->orWhere('target_role', 'like', "%{$careerTitle}%")
            ->first();

        if (!$career) {
            $career = Career::firstOrCreate(
                ['title' => $careerTitle],
                [
                    'target_role'      => $careerTitle,
                    'description'      => "Career path for {$careerTitle}",
                    'difficulty'       => 'Intermediate',
                    'average_duration' => $response['estimated_duration'] ?? '3 months',
                ]
            );
        }

        $roadmap = LearningRoadmap::create([
            'user_id'            => $userId,
            'career_id'          => $career->id,
            'title'              => $response['title'] ?? ("Roadmap to " . $careerTitle),
            'estimated_duration' => $response['estimated_duration'] ?? '3 months',
            'ai_response'        => $response,
            'status'             => 'active',
            'version'            => 1,
        ]);

        $items = $response['phases'] ?? $response['steps'] ?? [];
        if (!empty($items) && is_array($items)) {
            $seq = 1;
            foreach ($items as $item) {
                $title = $item['title'] ?? $item['phase_name'] ?? $item['name'] ?? ("Phase " . $seq);
                $description = $item['description'] ?? '';
                if (!empty($item['skills_covered']) && is_array($item['skills_covered'])) {
                    $skillsStr = implode(', ', $item['skills_covered']);
                    $description = $description ? ($description . " | Skills: " . $skillsStr) : ("Skills: " . $skillsStr);
                }
                $hours = isset($item['estimated_hours']) ? intval($item['estimated_hours']) : 20;

                RoadmapStep::create([
                    'roadmap_id'        => $roadmap->id,
                    'title'             => $title,
                    'description'       => $description,
                    'sequence'          => $seq++,
                    'estimated_hours'   => $hours,
                    'completion_status' => 'pending',
                ]);
            }
        }

        return $roadmap;
    }

    /**
     * POST /interviews/generate or /ai/prep-interview
     */
    public function prepInterview(Request $request): JsonResponse
    {
        $careerTitle = $request->input('career_title') ?? $request->input('target_role') ?? 'Full Stack Developer';
        $difficulty  = $request->input('difficulty', 'Intermediate');

        try {
            $response = $this->aiService->generateInterviewPrep($careerTitle, $difficulty);
            if (!$response) {
                throw new Exception('Failed to generate interview questions.');
            }
            return response()->json([
                'success' => true,
                'data'    => $response,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /resources/generate or /ai/generate-resources
     */
    public function generateResources(Request $request): JsonResponse
    {
        $careerTitle = $request->input('career_title') ?? $request->input('target_role') ?? 'Full Stack Developer';

        try {
            $response = $this->aiService->generateResources($careerTitle);
            if (!$response) {
                throw new Exception('Failed to generate learning resources.');
            }
            return response()->json([
                'success' => true,
                'data'    => $response,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
