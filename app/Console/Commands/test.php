<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class test extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->processParentQuestions();
    }

    private function processParentQuestions()
    {
        try {
            $requirementsWithLevelsJson = '[{"position_initial":0,"total_subtopics":4,"category_id":1,"subcategory_id":1,"type_material_id":11,"course_id":6,"course_name":"RAZONAMIENTO VERBAL","material":"Homework","category":"DECO con gr\u00e1ficos","subcategory":"Argumentativo","type_text_to_subcategory_id":1,"number_text":null,"topic_subquestions":"[{\\"quantity\\": 4, \\"topic_id\\": 203, \\"subtopic_id\\": 1328}, {\\"quantity\\": 2, \\"topic_id\\": 203, \\"subtopic_id\\": 1329}, {\\"quantity\\": 2, \\"topic_id\\": 204, \\"subtopic_id\\": 1331}, {\\"quantity\\": 2, \\"topic_id\\": 204, \\"subtopic_id\\": 1332}]","level_id":44,"position":0,"level":"NIVEL 2"}]';

            // JSON completo y sin alterar para las preguntas padre (parent_questions).
            $parentQuestionsJson = '[{"subquestion":"[{\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 4666}], \\"question_id\\": 2796}, {\\"topic_id\\": 189, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1308}], \\"question_id\\": 2797}]","parent_id":41,"category_id":1,"subcategory_id":1,"level_id":45,"course_id":6},{"subquestion":"[{\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1337}, {\\"id\\": 1330}], \\"question_id\\": 2953}, {\\"topic_id\\": 208, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1341}], \\"question_id\\": 2951}, {\\"topic_id\\": 205, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1336}], \\"question_id\\": 2952}, {\\"topic_id\\": 207, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1339}], \\"question_id\\": 2954}]","parent_id":42,"category_id":1,"subcategory_id":2,"level_id":44,"course_id":6},{"subquestion":"[{\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}], \\"question_id\\": 46239}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}], \\"question_id\\": 46240}]","parent_id":96,"category_id":1,"subcategory_id":3,"level_id":44,"course_id":6},{"subquestion":"[{\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}], \\"question_id\\": 46258}, {\\"topic_id\\": 204, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}, {\\"id\\": 1332}], \\"question_id\\": 46255}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}], \\"question_id\\": 46256}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}, {\\"id\\": 1330}], \\"question_id\\": 46257}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}], \\"question_id\\": 46259}]","parent_id":99,"category_id":1,"subcategory_id":3,"level_id":43,"course_id":6},{"subquestion":"[{\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}], \\"question_id\\": 46260}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}], \\"question_id\\": 46264}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}], \\"question_id\\": 46261}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}], \\"question_id\\": 46262}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}], \\"question_id\\": 46263}]","parent_id":100,"category_id":1,"subcategory_id":1,"level_id":44,"course_id":6},{"subquestion":"[{\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1341}], \\"question_id\\": 46276}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1341}], \\"question_id\\": 46280}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1341}], \\"question_id\\": 46277}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1341}], \\"question_id\\": 46278}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1341}], \\"question_id\\": 46279}]","parent_id":104,"category_id":1,"subcategory_id":1,"level_id":44,"course_id":6},{"subquestion":"[{\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}, {\\"id\\": 1329}, {\\"id\\": 1328}], \\"question_id\\": 46281}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}], \\"question_id\\": 46283}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}, {\\"id\\": 1329}, {\\"id\\": 1328}], \\"question_id\\": 46282}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}], \\"question_id\\": 46284}, {\\"topic_id\\": 204, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}, {\\"id\\": 1331}, {\\"id\\": 1332}], \\"question_id\\": 46285}]","parent_id":105,"category_id":2,"subcategory_id":1,"level_id":43,"course_id":6},{"subquestion":"[{\\"topic_id\\": 204, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1341}, {\\"id\\": 1331}, {\\"id\\": 1332}], \\"question_id\\": 46299}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1341}], \\"question_id\\": 46301}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1341}, {\\"id\\": 1329}, {\\"id\\": 1328}], \\"question_id\\": 46302}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1341}], \\"question_id\\": 46303}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1341}, {\\"id\\": 1329}, {\\"id\\": 1328}], \\"question_id\\": 46300}]","parent_id":107,"category_id":4,"subcategory_id":1,"level_id":43,"course_id":6},{"subquestion":"[{\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1341}, {\\"id\\": 1329}], \\"question_id\\": 46356}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1341}, {\\"id\\": 1328}], \\"question_id\\": 46355}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1341}], \\"question_id\\": 46357}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1341}], \\"question_id\\": 46358}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1341}, {\\"id\\": 1328}], \\"question_id\\": 46359}]","parent_id":113,"category_id":1,"subcategory_id":2,"level_id":45,"course_id":6},{"subquestion":"[{\\"topic_id\\": 204, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}, {\\"id\\": 1334}], \\"question_id\\": 47854}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}, {\\"id\\": 1329}], \\"question_id\\": 47855}]","parent_id":125,"category_id":1,"subcategory_id":4,"level_id":44,"course_id":6},{"subquestion":"[{\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1329}, {\\"id\\": 1328}, {\\"id\\": 1329}], \\"question_id\\": 47959}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}, {\\"id\\": 1329}, {\\"id\\": 1328}], \\"question_id\\": 47960}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1329}, {\\"id\\": 1328}, {\\"id\\": 1330}], \\"question_id\\": 47961}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1329}, {\\"id\\": 1328}], \\"question_id\\": 47962}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}, {\\"id\\": 1329}, {\\"id\\": 1328}], \\"question_id\\": 47963}]","parent_id":126,"category_id":1,"subcategory_id":3,"level_id":43,"course_id":6},{"subquestion":"[{\\"topic_id\\": 204, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}, {\\"id\\": 1332}, {\\"id\\": 1334}, {\\"id\\": 1331}], \\"question_id\\": 48007}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}], \\"question_id\\": 48008}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}, {\\"id\\": 1330}, {\\"id\\": 1329}, {\\"id\\": 1328}], \\"question_id\\": 48009}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}], \\"question_id\\": 48010}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}], \\"question_id\\": 48011}]","parent_id":127,"category_id":1,"subcategory_id":3,"level_id":44,"course_id":6},{"subquestion":"[{\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}], \\"question_id\\": 48053}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}, {\\"id\\": 4666}, {\\"id\\": 1330}, {\\"id\\": 1329}, {\\"id\\": 1328}], \\"question_id\\": 48051}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}], \\"question_id\\": 48050}, {\\"topic_id\\": 204, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}, {\\"id\\": 1331}, {\\"id\\": 1333}, {\\"id\\": 1334}, {\\"id\\": 1332}], \\"question_id\\": 48052}, {\\"topic_id\\": 204, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}, {\\"id\\": 1331}, {\\"id\\": 1333}, {\\"id\\": 1334}, {\\"id\\": 1332}], \\"question_id\\": 48049}]","parent_id":128,"category_id":1,"subcategory_id":2,"level_id":43,"course_id":6},{"subquestion":"[{\\"topic_id\\": 204, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}, {\\"id\\": 1334}, {\\"id\\": 1333}], \\"question_id\\": 48093}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}], \\"question_id\\": 48092}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}], \\"question_id\\": 48090}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}, {\\"id\\": 1329}, {\\"id\\": 1328}], \\"question_id\\": 48091}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}], \\"question_id\\": 48094}]","parent_id":129,"category_id":1,"subcategory_id":4,"level_id":43,"course_id":6},{"subquestion":"[{\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}, {\\"id\\": 1339}], \\"question_id\\": 48123}, {\\"topic_id\\": 204, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}, {\\"id\\": 1339}, {\\"id\\": 1334}, {\\"id\\": 1333}], \\"question_id\\": 48124}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}, {\\"id\\": 1339}, {\\"id\\": 4666}, {\\"id\\": 1330}, {\\"id\\": 1329}, {\\"id\\": 1328}], \\"question_id\\": 48125}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}, {\\"id\\": 1339}, {\\"id\\": 1329}, {\\"id\\": 1328}], \\"question_id\\": 48127}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1338}, {\\"id\\": 1339}, {\\"id\\": 1329}, {\\"id\\": 1328}], \\"question_id\\": 48122}]","parent_id":130,"category_id":1,"subcategory_id":4,"level_id":44,"course_id":6},{"subquestion":"[{\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1340}, {\\"id\\": 1341}, {\\"id\\": 1328}, {\\"id\\": 1329}], \\"question_id\\": 48152}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1340}, {\\"id\\": 1341}], \\"question_id\\": 48153}, {\\"topic_id\\": 204, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1340}, {\\"id\\": 1341}, {\\"id\\": 1333}, {\\"id\\": 1332}], \\"question_id\\": 48154}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1340}, {\\"id\\": 1341}, {\\"id\\": 1329}, {\\"id\\": 1328}], \\"question_id\\": 48155}, {\\"topic_id\\": 204, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1340}, {\\"id\\": 1341}, {\\"id\\": 1331}, {\\"id\\": 1333}, {\\"id\\": 1334}, {\\"id\\": 1332}], \\"question_id\\": 48156}]","parent_id":131,"category_id":1,"subcategory_id":1,"level_id":44,"course_id":6},{"subquestion":"[{\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}, {\\"id\\": 1330}, {\\"id\\": 1329}, {\\"id\\": 4666}], \\"question_id\\": 48185}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1328}, {\\"id\\": 1330}, {\\"id\\": 1329}, {\\"id\\": 4666}], \\"question_id\\": 48186}]","parent_id":132,"category_id":1,"subcategory_id":2,"level_id":45,"course_id":6},{"subquestion":"[{\\"topic_id\\": 204, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1342}, {\\"id\\": 1343}, {\\"id\\": 1334}, {\\"id\\": 1333}, {\\"id\\": 1332}], \\"question_id\\": 48266}, {\\"topic_id\\": 204, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1342}, {\\"id\\": 1343}, {\\"id\\": 1334}, {\\"id\\": 1333}, {\\"id\\": 1332}], \\"question_id\\": 48265}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1342}, {\\"id\\": 1343}, {\\"id\\": 1328}, {\\"id\\": 1329}], \\"question_id\\": 48267}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1342}, {\\"id\\": 1343}, {\\"id\\": 1329}, {\\"id\\": 1328}], \\"question_id\\": 48268}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1342}, {\\"id\\": 1343}], \\"question_id\\": 48269}]","parent_id":135,"category_id":1,"subcategory_id":1,"level_id":44,"course_id":6},{"subquestion":"[{\\"topic_id\\": 204, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1333}, {\\"id\\": 1331}, {\\"id\\": 1332}, {\\"id\\": 1334}, {\\"id\\": 1333}], \\"question_id\\": 48309}, {\\"topic_id\\": 204, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1332}, {\\"id\\": 1334}, {\\"id\\": 1331}, {\\"id\\": 1333}, {\\"id\\": 1332}], \\"question_id\\": 48308}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1331}, {\\"id\\": 1332}, {\\"id\\": 1333}, {\\"id\\": 1334}, {\\"id\\": 1328}], \\"question_id\\": 48310}, {\\"topic_id\\": 204, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1333}, {\\"id\\": 1331}, {\\"id\\": 1332}, {\\"id\\": 1334}, {\\"id\\": 1333}], \\"question_id\\": 48311}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1331}, {\\"id\\": 1332}, {\\"id\\": 1333}, {\\"id\\": 1334}], \\"question_id\\": 48312}]","parent_id":137,"category_id":1,"subcategory_id":3,"level_id":43,"course_id":6},{"subquestion":"[{\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1340}, {\\"id\\": 1341}], \\"question_id\\": 48329}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1340}, {\\"id\\": 1341}], \\"question_id\\": 48330}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1340}, {\\"id\\": 1341}, {\\"id\\": 1328}], \\"question_id\\": 48331}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1340}, {\\"id\\": 1341}], \\"question_id\\": 48332}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1340}, {\\"id\\": 1341}], \\"question_id\\": 48333}]","parent_id":138,"category_id":1,"subcategory_id":3,"level_id":43,"course_id":6},{"subquestion":"[{\\"topic_id\\": 204, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1340}, {\\"id\\": 1341}, {\\"id\\": 1334}], \\"question_id\\": 48335}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1340}, {\\"id\\": 1341}], \\"question_id\\": 48334}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1340}, {\\"id\\": 1341}], \\"question_id\\": 48336}, {\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1340}, {\\"id\\": 1341}, {\\"id\\": 1329}], \\"question_id\\": 48337}, {\\"topic_id\\": null, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1340}, {\\"id\\": 1341}], \\"question_id\\": 48338}]","parent_id":139,"category_id":1,"subcategory_id":4,"level_id":44,"course_id":6},{"subquestion":"[{\\"topic_id\\": 203, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1331}, {\\"id\\": 1332}, {\\"id\\": 1329}], \\"question_id\\": 48565}, {\\"topic_id\\": 204, \\"course_id\\": 6, \\"subtopics\\": [{\\"id\\": 1331}, {\\"id\\": 1332}, {\\"id\\": 1333}], \\"question_id\\": 48566}]","parent_id":140,"category_id":1,"subcategory_id":2,"level_id":44,"course_id":6}]';



            // Decodificar el JSON principal
            $requirementsWithLevels = collect(json_decode($requirementsWithLevelsJson));
            $parent_questions = collect(json_decode($parentQuestionsJson))->whereIn('parent_id', [131]);
            // Log::info('parent: ' . var_export($parent_questions, true));

            // Inicializar variables de estado.
            $selectedQuestions = collect();
            $usedParentQuestionIds = [];

            // --- 2. LÓGICA PRINCIPAL DE PROCESAMIENTO ---
            foreach ($requirementsWithLevels as $expected) {
                $bestCandidate = null;
                $maxMatches = -1;
                $bestCandidateData = [];

                // Filtra candidatos por categoría, subcategoría, nivel y que no hayan sido usados.
                $candidates = collect($parent_questions)->where('category_id', $expected->category_id)
                    ->where('subcategory_id', $expected->subcategory_id)
                    ->where('level_id', '>=', $expected->level_id)
                    ->whereNotIn('parent_id', $usedParentQuestionIds);

                if ($candidates->isEmpty()) {
                    continue;
                }

                $expectedSubQuestions = collect(json_decode($expected->topic_subquestions, true));

                // Itera sobre cada candidato para encontrar el que mejor se ajuste.
                foreach ($candidates as $candidate) {

                    // --- LÓGICA DE SELECCIÓN DE PREGUNTAS (CORREGIDA) ---
                    $availableSubQuestions = collect(json_decode($candidate->subquestion, true));

                    // Hacemos una copia del inventario del candidato para usarla como un "pool" que se agotará.
                    $questionsPool = $availableSubQuestions->keyBy('question_id');
                    Log::info('questionsPool: ' . var_export($questionsPool, true));

                    // Aquí guardaremos las sub-preguntas que seleccionemos para ESTE candidato.
                    $selectedSubs = collect();

                    // Usamos un bucle para controlar el estado del pool.
                    foreach ($expectedSubQuestions as $expectedSub) {
                        $requiredQty = $expectedSub['quantity'] ?? 1;

                        // Buscamos en el POOL ACTUAL, no en la lista original.
                        $matchingFromPool = $questionsPool->filter(function ($aq) use ($expectedSub) {
                            return ($aq['topic_id'] == $expectedSub['topic_id'] || is_null($expectedSub['topic_id']))
                                && collect($aq['subtopics'])->contains('id', $expectedSub['subtopic_id']);
                        });

                        $taken = $matchingFromPool->take($requiredQty);

                        if ($taken->isNotEmpty()) {
                            $selectedSubs->push(...$taken->values());
                            // ¡CRUCIAL! Eliminamos los que ya usamos del pool.
                            $questionsPool->forget($taken->pluck('question_id')->all());
                        }
                    }

                    $matchCount = $selectedSubs->count();

                    // Si este candidato es mejor que el anterior, lo guardamos.
                    if ($matchCount > $maxMatches) {
                        $maxMatches = $matchCount;
                        $bestCandidate = $candidate;

                        // --- LÓGICA DE GENERACIÓN DE REPORTE (CORREGIDA) ---

                        // 1. Creamos un "pool" con las preguntas que SÍ se seleccionaron.
                        $poolOfSelectedQuestions = $selectedSubs->keyBy('question_id');

                        // 2. Aquí construiremos la lista final del reporte sin duplicados.
                        $finalStatusList = collect();

                        // 3. Iteramos sobre la "lista de compras" original.
                        foreach ($expectedSubQuestions as $expectedSub) {
                            $requiredQty = $expectedSub['quantity'];
                            $foundCount = 0;

                            // 4. Intentamos cumplir la cantidad requerida.
                            for ($i = 0; $i < $requiredQty; $i++) {

                                // 5. Buscamos la PRIMERA pregunta en el pool que cumpla el criterio.
                                $foundQuestion = $poolOfSelectedQuestions->first(function ($s) use ($expectedSub) {
                                    return ($s['topic_id'] == $expectedSub['topic_id'] || is_null($expectedSub['topic_id']))
                                        && collect($s['subtopics'])->contains('id', $expectedSub['subtopic_id']);
                                });

                                // 6. Si la encontramos...
                                if ($foundQuestion) {
                                    $finalStatusList->push([
                                        'topic_id' => $expectedSub['topic_id'],
                                        'subtopic_id' => $expectedSub['subtopic_id'],
                                        'question_id' => $foundQuestion['question_id'],
                                        'course_id' => $foundQuestion['course_id'],
                                        'status' => 'encontrado'
                                    ]);
                                    $foundCount++;

                                    // ¡CRUCIAL! La eliminamos del pool para que no pueda ser usada de nuevo.
                                    $poolOfSelectedQuestions->forget($foundQuestion['question_id']);
                                } else {
                                    break; // No hay más preguntas en el pool para este subtema.
                                }
                            }

                            // 7. Añadimos los registros "faltante" para lo que no se pudo cumplir.
                            $missingQty = $requiredQty - $foundCount;
                            for ($i = 0; $i < $missingQty; $i++) {
                                $finalStatusList->push([
                                    'topic_id' => $expectedSub['topic_id'],
                                    'subtopic_id' => $expectedSub['subtopic_id'],
                                    'course_id' => $expected->course_id,
                                    'question_id' => null,
                                    'status' => 'faltante'
                                ]);
                            }
                        }

                        $bestCandidateData = [
                            'selected' => $selectedSubs,
                            'distribution_status' => $finalStatusList,
                        ];
                    }
                }

                // --- 3. GUARDADO DEL RESULTADO ---
                // Si se encontró un buen candidato, se añade a los resultados.
                if ($bestCandidate && $bestCandidateData['selected']->isNotEmpty()) {
                    // Añade la información relevante al objeto del candidato antes de guardarlo.
                    $bestCandidate->selected_subquestions = $bestCandidateData['distribution_status'];
                    $bestCandidate->topic_subquestions = $expected->topic_subquestions;
                    $bestCandidate->position = $expected->position_initial;
                    $bestCandidate->position_initial = $expected->position_initial;
                    $bestCandidate->week_type_material_id = $expected->type_material_id;

                    $selectedQuestions->push($bestCandidate);
                    $usedParentQuestionIds[] = $bestCandidate->parent_id;
                }
            }
            // Log::info('selectedQuestions: ' . var_export($selectedQuestions, true));
        } catch (\Throwable $th) {
            Log::error('Error en el proceso de selección de textos: ' . $th->getMessage() . ' en la línea ' . $th->getLine());
            throw $th;
        }
    }
}
