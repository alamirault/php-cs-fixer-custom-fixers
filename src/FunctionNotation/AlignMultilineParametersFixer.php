<?php
declare(strict_types=1);

namespace ErickSkrauch\PhpCsFixer\FunctionNotation;

use ErickSkrauch\PhpCsFixer\AbstractFixer;
use PhpCsFixer\Fixer\ConfigurableFixerInterface;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolver;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolverInterface;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Analyzer\Analysis\TypeAnalysis;
use PhpCsFixer\Tokenizer\Analyzer\FunctionsAnalyzer;
use PhpCsFixer\Tokenizer\Analyzer\WhitespacesAnalyzer;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Tokenizer\TokensAnalyzer;
use SplFileInfo;

/**
 * @property array{
 *     variables: bool|null,
 *     defaults: bool|null,
 * } $configuration
 *
 * @phpstan-type DeclarationAnalysis array{
 *     typeLength: non-negative-int,
 *     nameLength: positive-int,
 *     nameIndex: int,
 * }
 */
final class AlignMultilineParametersFixer extends AbstractFixer implements ConfigurableFixerInterface, WhitespacesAwareFixerInterface {

    /**
     * @internal
     */
    public const C_VARIABLES = 'variables';
    /**
     * @internal
     */
    public const C_DEFAULTS = 'defaults';

    /**
     * @var list<int>
     */
    private const PROMOTIONAL_TOKENS = [
        CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PUBLIC,
        CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PROTECTED,
        CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PRIVATE,
    ];

    public function getDefinition(): FixerDefinitionInterface {
        return new FixerDefinition(
            'Aligns parameters in multiline function declaration.',
            [
                new CodeSample(
                    '<?php
function test(
    string $a,
    int $b = 0
): void {};
',
                ),
                new CodeSample(
                    '<?php
function test(
    string $string,
    int    $int    = 0
): void {};
',
                    [self::C_VARIABLES => false, self::C_DEFAULTS => false],
                ),
            ],
        );
    }

    public function isCandidate(Tokens $tokens): bool {
        return $tokens->isAnyTokenKindsFound([T_FUNCTION, T_FN]);
    }

    /**
     * Must run after StatementIndentationFixer, MethodArgumentSpaceFixer, CompactNullableTypehintFixer,
     *                SingleSpaceAroundConstructFixer, TypesSpacesFixer
     */
    public function getPriority(): int {
        return -10;
    }

    protected function createConfigurationDefinition(): FixerConfigurationResolverInterface {
        return new FixerConfigurationResolver([
            (new FixerOptionBuilder(self::C_VARIABLES, 'On null no value alignment, on bool forces alignment.'))
                ->setAllowedTypes(['bool', 'null'])
                ->setDefault(true)
                ->getOption(),
            (new FixerOptionBuilder(self::C_DEFAULTS, 'On null no value alignment, on bool forces alignment.'))
                ->setAllowedTypes(['bool', 'null'])
                ->setDefault(null)
                ->getOption(),
        ]);
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void {
        // There is nothing to do
        if ($this->configuration[self::C_VARIABLES] === null && $this->configuration[self::C_DEFAULTS] === null) {
            return;
        }

        $tokensAnalyzer = new TokensAnalyzer($tokens);
        $functionsAnalyzer = new FunctionsAnalyzer();
        /** @var \PhpCsFixer\Tokenizer\Token $functionToken */
        foreach ($tokens as $i => $functionToken) {
            if (!$functionToken->isGivenKind([T_FUNCTION, T_FN])) {
                continue;
            }

            $openBraceIndex = $tokens->getNextTokenOfKind($i, ['(']);
            $isMultiline = $tokensAnalyzer->isBlockMultiline($tokens, $openBraceIndex);
            if (!$isMultiline) {
                continue;
            }

            /** @var \PhpCsFixer\Tokenizer\Analyzer\Analysis\ArgumentAnalysis[] $arguments */
            $arguments = $functionsAnalyzer->getFunctionArguments($tokens, $i);
            if ($arguments === []) {
                continue;
            }

            $longestType = 0;
            $longestVariableName = 0;
            $hasAtLeastOneTypedArgument = false;
            /** @var list<DeclarationAnalysis> $analysedArguments */
            $analysedArguments = [];
            foreach ($arguments as $argument) {
                $typeAnalysis = $argument->getTypeAnalysis();
                $declarationAnalysis = $this->getDeclarationAnalysis($tokens, $argument->getNameIndex(), $typeAnalysis);
                if ($declarationAnalysis['typeLength'] > 0) {
                    $hasAtLeastOneTypedArgument = true;
                }

                if ($declarationAnalysis['typeLength'] > $longestType) {
                    $longestType = $declarationAnalysis['typeLength'];
                }

                if ($declarationAnalysis['nameLength'] > $longestVariableName) {
                    $longestVariableName = $declarationAnalysis['nameLength'];
                }

                $analysedArguments[] = $declarationAnalysis;
            }

            $argsIndent = WhitespacesAnalyzer::detectIndent($tokens, $i) . $this->whitespacesConfig->getIndent();
            // Since we perform insertion of new tokens in this loop, if we go sequentially,
            // at each new iteration the token indices will shift due to the addition of new whitespaces.
            // If we go from the end, this problem will not occur.
            foreach (array_reverse($analysedArguments) as $argument) {
                if ($this->configuration[self::C_DEFAULTS] !== null) {
                    // Can't use $argument->hasDefault() because it's null when it's default for a type (e.g. 0 for int)
                    $equalToken = $tokens[$tokens->getNextMeaningfulToken($argument['nameIndex'])];
                    if ($equalToken->getContent() === '=') {
                        $whitespaceIndex = $argument['nameIndex'] + 1;
                        if ($this->configuration[self::C_DEFAULTS] === true) {
                            $tokens->ensureWhitespaceAtIndex($whitespaceIndex, 0, str_repeat(' ', $longestVariableName - $argument['nameLength'] + 1));
                        } else {
                            $tokens->ensureWhitespaceAtIndex($whitespaceIndex, 0, ' ');
                        }
                    }
                }

                if ($this->configuration[self::C_VARIABLES] !== null) {
                    $whitespaceIndex = $argument['nameIndex'] - 1;
                    if ($this->configuration[self::C_VARIABLES] === true) {
                        $appendix = str_repeat(' ', $longestType - $argument['typeLength'] + (int)$hasAtLeastOneTypedArgument);
                        if ($argument['typeLength'] > 0) {
                            $whitespaceToken = $appendix;
                        } else {
                            $whitespaceToken = $this->whitespacesConfig->getLineEnding() . $argsIndent . $appendix;
                        }
                    } elseif ($argument['typeLength'] > 0) {
                        $whitespaceToken = ' ';
                    } else {
                        $whitespaceToken = $this->whitespacesConfig->getLineEnding() . $argsIndent;
                    }

                    $tokens->ensureWhitespaceAtIndex($whitespaceIndex, 1, $whitespaceToken);
                }
            }
        }
    }

    /**
     * @phpstan-return DeclarationAnalysis
     */
    private function getDeclarationAnalysis(Tokens $tokens, int $nameIndex, ?TypeAnalysis $typeAnalysis): array {
        $searchIndex = $nameIndex;
        $includeNextWhitespace = false;
        $typeLength = 0;
        if ($typeAnalysis !== null) {
            $searchIndex = $typeAnalysis->getStartIndex();
            $includeNextWhitespace = true;
            for ($i = $typeAnalysis->getStartIndex(); $i <= $typeAnalysis->getEndIndex(); $i++) {
                $typeLength += mb_strlen($tokens[$i]->getContent());
            }
        }

        $readonlyTokenIndex = $tokens->getPrevMeaningfulToken($searchIndex);
        $readonlyToken = $tokens[$readonlyTokenIndex];
        if (defined('T_READONLY') && $readonlyToken->isGivenKind(T_READONLY)) {
            // The readonly can't be assigned on a promoted property without a type,
            // so there is always will be a space between readonly and the next token
            $whitespaceToken = $tokens[$searchIndex - 1];
            $typeLength += strlen($readonlyToken->getContent() . $whitespaceToken->getContent());
            $searchIndex = $readonlyTokenIndex;
            $includeNextWhitespace = true;
        }

        $promotionTokenIndex = $tokens->getPrevMeaningfulToken($searchIndex);
        $promotionToken = $tokens[$promotionTokenIndex];
        if ($promotionToken->isGivenKind(self::PROMOTIONAL_TOKENS)) {
            $promotionalStr = $promotionToken->getContent();
            if ($includeNextWhitespace) {
                $whitespaceToken = $tokens[$promotionTokenIndex + 1];
                if ($whitespaceToken->isWhitespace()) {
                    $promotionalStr .= $whitespaceToken->getContent();
                }
            }

            $typeLength += strlen($promotionalStr);
        }

        /** @var positive-int $nameLength force type for PHPStan to avoid type error on return statement */
        $nameLength = mb_strlen($tokens[$nameIndex]->getContent());

        return [
            'typeLength' => $typeLength,
            'nameLength' => $nameLength,
            'nameIndex' => $nameIndex,
        ];
    }

}
