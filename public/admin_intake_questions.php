<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Reference\IntakeQuestionRepository;
use Pfpms\Reference\IntakeQuestionService;
use Pfpms\Security\Csrf;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// Intake questions (US-09): add, edit, reorder, retire and restore the extra registration questions. ?edit= edits one.
$ctx = Page::start(['capability' => 'intake_question.manage']);
$questionId = Request::isPost() ? Request::int('question_id') : Request::int('edit', fromQuery: true);
$target = $questionId !== null ? (IntakeQuestionRepository::find($questionId) ?? Response::notFound()) : null;
$action = Request::isPost() ? Request::string('action') : ($target ? 'save' : 'add');
$editing = $action === 'save' ? $target : null;
$values = $editing
    ? ['prompt' => $editing['prompt'], 'answer_type' => $editing['answer_type'], 'is_required' => $editing['is_required'],
        'options' => implode("\n", IntakeQuestionService::optionList($editing['options']))]
    : ['prompt' => '', 'answer_type' => 'Text', 'options' => '', 'is_required' => 0];
$errors = [];

if (Request::isPost()) {
    Csrf::verify();
    try {
        if ($action === 'add') {
            $values = Request::only(['prompt', 'answer_type', 'options']) + ['is_required' => Request::bool('is_required')];
            IntakeQuestionService::create($values, $ctx->userId());
            Flash::success('Question added. New registrations ask it straight away.');
        } elseif ($target === null) {
            Response::badRequest();
        } elseif ($action === 'save') {
            $id = (int) $target['question_id'];
            if (Request::string('prompt') === null) { // the form for an answered question only offers "required"
                $values['is_required'] = Request::bool('is_required');
                IntakeQuestionService::setRequired($id, $values['is_required']);
            } else {
                $values = Request::only(['prompt', 'answer_type', 'options']) + ['is_required' => Request::bool('is_required')];
                IntakeQuestionService::update($id, $values);
            }
            Flash::success('Question saved.');
        } elseif ($action === 'up' || $action === 'down') {
            IntakeQuestionService::move((int) $target['question_id'], $action);
        } elseif ($action === 'retire') {
            IntakeQuestionService::retire((int) $target['question_id']);
            Flash::success('Question retired. New registrations no longer ask it; answers already given are kept.');
        } elseif ($action === 'restore') {
            IntakeQuestionService::restore((int) $target['question_id']);
            Flash::success('Question restored. It is asked again, at the end of the list.');
        } else {
            Response::badRequest();
        }
        Response::redirect('admin_intake_questions.php');
    } catch (ValidationException $e) {
        if ($action !== 'add' && $action !== 'save') {
            Flash::error($e->getMessage());
            Response::redirect('admin_intake_questions.php');
        }
        $errors = $e->errors;
    }
}

View::render('pages/admin/intake_questions', [
    'title' => 'Intake questions', 'ctx' => $ctx, 'active' => IntakeQuestionRepository::active(), 'retired' => IntakeQuestionRepository::retired(),
    'editing' => $editing, 'values' => $values, 'errors' => $errors, 'timeZone' => $ctx->site()['time_zone'] ?? 'America/New_York',
]);
