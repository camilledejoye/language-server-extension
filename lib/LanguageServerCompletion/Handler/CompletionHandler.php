<?php

namespace Phpactor\Extension\LanguageServerCompletion\Handler;

use Amp\CancellationToken;
use Amp\CancelledException;
use Amp\Delayed;
use Amp\Promise;
use LanguageServerProtocol\CompletionItem;
use LanguageServerProtocol\CompletionList;
use LanguageServerProtocol\CompletionOptions;
use LanguageServerProtocol\InsertTextFormat;
use LanguageServerProtocol\Position;
use LanguageServerProtocol\Range;
use LanguageServerProtocol\ServerCapabilities;
use LanguageServerProtocol\SignatureHelpOptions;
use LanguageServerProtocol\TextDocumentItem;
use LanguageServerProtocol\TextEdit;
use Phpactor\Completion\Core\Completor;
use Phpactor\Completion\Core\Suggestion;
use Phpactor\Completion\Core\TypedCompletorRegistry;
use Phpactor\Extension\LanguageServerCompletion\Util\PhpactorToLspCompletionType;
use Phpactor\Extension\LanguageServerCompletion\Util\SuggestionNameFormatter;
use Phpactor\Extension\LanguageServer\Helper\OffsetHelper;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Session\Workspace;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;

class CompletionHandler implements Handler, CanRegisterCapabilities
{
    /**
     * @var Completor
     */
    private $completor;

    /**
     * @var TypedCompletorRegistry
     */
    private $registry;

    /**
     * @var bool
     */
    private $provideTextEdit;

    /**
     * @var SuggestionNameFormatter
     */
    private $suggestionNameFormatter;

    /**
     * @var Workspace
     */
    private $workspace;

    /**
     * @var bool
     */
    private $supportSnippets;

    public function __construct(
        Workspace $workspace,
        TypedCompletorRegistry $registry,
        SuggestionNameFormatter $suggestionNameFormatter,
        bool $supportSnippets,
        bool $provideTextEdit = false
    ) {
        $this->registry = $registry;
        $this->provideTextEdit = $provideTextEdit;
        $this->workspace = $workspace;
        $this->suggestionNameFormatter = $suggestionNameFormatter;
        $this->supportSnippets = $supportSnippets;
    }

    public function methods(): array
    {
        return [
            'textDocument/completion' => 'completion',
        ];
    }

    public function completion(TextDocumentItem $textDocument, Position $position, CancellationToken $token): Promise
    {
        return \Amp\call(function () use ($textDocument, $position, $token) {
            $textDocument = $this->workspace->get($textDocument->uri);

            $languageId = $textDocument->languageId ?: 'php';
            $suggestions = $this->registry->completorForType(
                $languageId
            )->complete(
                TextDocumentBuilder::create($textDocument->text)->language($languageId)->uri($textDocument->uri)->build(),
                ByteOffset::fromInt($position->toOffset($textDocument->text))
            );

            $completionList = new CompletionList();
            $completionList->isIncomplete = false;

            foreach ($suggestions as $suggestion) {
                $name = $this->suggestionNameFormatter->format($suggestion);
                $insertText = $name;
                $insertTextFormat = InsertTextFormat::PLAIN_TEXT;

                if ($this->supportSnippets) {
                    $insertText = $suggestion->snippet() ?: $name;
                    $insertTextFormat = $suggestion->snippet()
                        ? InsertTextFormat::SNIPPET
                        : InsertTextFormat::PLAIN_TEXT
                    ;
                }

                $completionList->items[] = new CompletionItem(
                    $name,
                    PhpactorToLspCompletionType::fromPhpactorType($suggestion->type()),
                    $suggestion->shortDescription(),
                    $suggestion->documentation(),
                    null,
                    null,
                    $insertText,
                    $this->textEdit($suggestion, $textDocument),
                    null,
                    null,
                    null,
                    $insertTextFormat
                );

                try {
                    $token->throwIfRequested();
                } catch (CancelledException $cancellation) {
                    $completionList->isIncomplete = true;
                    break;
                }
                yield new Delayed(0);
            }

            $completionList->isIncomplete = $completionList->isIncomplete || !$suggestions->getReturn();

            return $completionList;
        });
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->completionProvider = new CompletionOptions(false, [':', '>', '$']);
        $capabilities->signatureHelpProvider = new SignatureHelpOptions(['(', ',']);
    }

    private function textEdit(Suggestion $suggestion, TextDocumentItem $textDocument): ?TextEdit
    {
        if (false === $this->provideTextEdit) {
            return null;
        }

        $range = $suggestion->range();

        if (!$range) {
            return null;
        }

        return new TextEdit(
            new Range(
                OffsetHelper::offsetToPosition($textDocument->text, $range->start()->toInt()),
                OffsetHelper::offsetToPosition($textDocument->text, $range->end()->toInt())
            ),
            $suggestion->name()
        );
    }
}
