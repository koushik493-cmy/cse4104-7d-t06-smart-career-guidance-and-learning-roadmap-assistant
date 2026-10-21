<?php

namespace App\Services;

use App\Models\LearningRoadmap;
use App\Models\RoadmapStep;
use Illuminate\Support\Facades\DB;

class ProgressService
{
    public function getProgress(int $userId)
    {
        $roadmaps = LearningRoadmap::where('user_id', $userId)
            ->with('steps')
            ->orderBy('created_at', 'desc')
            ->get();
        
        $progress = [];
        foreach ($roadmaps as $roadmap) {
            $totalSteps = $roadmap->steps->count();
            $completedStepIds = DB::table('completed_steps')
                ->where('user_id', $userId)
                ->whereIn('roadmap_step_id', $roadmap->steps->pluck('id'))
                ->pluck('roadmap_step_id')
                ->toArray();

            $completedSteps = count($completedStepIds);
                
            $percentage = $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0;
            
            $steps = $roadmap->steps->map(function ($step) use ($completedStepIds) {
                $step->completion_status = in_array($step->id, $completedStepIds) ? 'completed' : 'pending';
                return $step;
            });
            
            $progress[] = [
                'roadmap_id' => $roadmap->id,
                'title' => $roadmap->title,
                'total_steps' => $totalSteps,
                'completed_steps' => $completedSteps,
                'percentage' => $percentage,
                'steps' => $steps
            ];
        }
        
        return $progress;
    }

    public function completeStep(int $userId, int $stepId)
    {
        $step = RoadmapStep::findOrFail($stepId);
        
        // Ensure not already completed
        $exists = DB::table('completed_steps')
            ->where('user_id', $userId)
            ->where('roadmap_step_id', $stepId)
            ->exists();
            
        if (!$exists) {
            DB::table('completed_steps')->insert([
                'user_id' => $userId,
                'roadmap_step_id' => $stepId,
                'completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            $step->completion_status = 'completed';
            $step->save();
        }
        
        return $this->getProgress($userId);
    }
}
