# Clarity Engine API

## Classes, Interfaces & Traits overview

### `Clarity`

- [ClarityEngine](Clarity_ClarityEngine.md) `Clarity\ClarityEngine`
- [ClarityEngineTrait](Clarity_ClarityEngineTrait.md) `Clarity\ClarityEngineTrait`
- [ClarityException](Clarity_ClarityException.md) `Clarity\ClarityException`
- [ModuleInterface](Clarity_ModuleInterface.md) `Clarity\ModuleInterface`

### `Clarity\Debug`

- [CliDumpRenderer](Clarity_Debug_CliDumpRenderer.md) `Clarity\Debug\CliDumpRenderer`
- [CssDumpRenderer](Clarity_Debug_CssDumpRenderer.md) `Clarity\Debug\CssDumpRenderer`
- [DebugEvent](Clarity_Debug_DebugEvent.md) `Clarity\Debug\DebugEvent`
- [DebugEventBus](Clarity_Debug_DebugEventBus.md) `Clarity\Debug\DebugEventBus`
- [DebugListener](Clarity_Debug_DebugListener.md) `Clarity\Debug\DebugListener`
- [DebugRuntime](Clarity_Debug_DebugRuntime.md) `Clarity\Debug\DebugRuntime`
- [DumpOptions](Clarity_Debug_DumpOptions.md) `Clarity\Debug\DumpOptions`
- [DumpRenderer](Clarity_Debug_DumpRenderer.md) `Clarity\Debug\DumpRenderer`
- [HtmlDebugPanel](Clarity_Debug_HtmlDebugPanel.md) `Clarity\Debug\HtmlDebugPanel`
- [HtmlDumpRenderer](Clarity_Debug_HtmlDumpRenderer.md) `Clarity\Debug\HtmlDumpRenderer`
- [JsDumpRenderer](Clarity_Debug_JsDumpRenderer.md) `Clarity\Debug\JsDumpRenderer`

### `Clarity\Engine`

- [Cache](Clarity_Engine_Cache.md) `Clarity\Engine\Cache`
- [CompiledTemplate](Clarity_Engine_CompiledTemplate.md) `Clarity\Engine\CompiledTemplate`
- [Compiler](Clarity_Engine_Compiler.md) `Clarity\Engine\Compiler`
- [Directive](Clarity_Engine_Directive.md) `Clarity\Engine\Directive`
- [Policy](Clarity_Engine_Policy.md) `Clarity\Engine\Policy`
- [Registry](Clarity_Engine_Registry.md) `Clarity\Engine\Registry`
- [SourceMap](Clarity_Engine_SourceMap.md) `Clarity\Engine\SourceMap`
- [Tokenizer](Clarity_Engine_Tokenizer.md) `Clarity\Engine\Tokenizer`

### `Clarity\Engine\Compiler`

- [BodyCompilerTrait](Clarity_Engine_Compiler_BodyCompilerTrait.md) `Clarity\Engine\Compiler\BodyCompilerTrait`
- [CodeBuilderTrait](Clarity_Engine_Compiler_CodeBuilderTrait.md) `Clarity\Engine\Compiler\CodeBuilderTrait`
- [CompilerCoreTrait](Clarity_Engine_Compiler_CompilerCoreTrait.md) `Clarity\Engine\Compiler\CompilerCoreTrait`
- [ControlFlowTrait](Clarity_Engine_Compiler_ControlFlowTrait.md) `Clarity\Engine\Compiler\ControlFlowTrait`
- [DirectiveSupportTrait](Clarity_Engine_Compiler_DirectiveSupportTrait.md) `Clarity\Engine\Compiler\DirectiveSupportTrait`
- [InheritanceTrait](Clarity_Engine_Compiler_InheritanceTrait.md) `Clarity\Engine\Compiler\InheritanceTrait`
- [PairedDirectiveTrait](Clarity_Engine_Compiler_PairedDirectiveTrait.md) `Clarity\Engine\Compiler\PairedDirectiveTrait`

### `Clarity\Engine\Tokenizer`

- [CallableTrait](Clarity_Engine_Tokenizer_CallableTrait.md) `Clarity\Engine\Tokenizer\CallableTrait`
- [CastTrait](Clarity_Engine_Tokenizer_CastTrait.md) `Clarity\Engine\Tokenizer\CastTrait`
- [CollectionLiteralTrait](Clarity_Engine_Tokenizer_CollectionLiteralTrait.md) `Clarity\Engine\Tokenizer\CollectionLiteralTrait`
- [ExpressionCoreTrait](Clarity_Engine_Tokenizer_ExpressionCoreTrait.md) `Clarity\Engine\Tokenizer\ExpressionCoreTrait`
- [ExpressionSupportTrait](Clarity_Engine_Tokenizer_ExpressionSupportTrait.md) `Clarity\Engine\Tokenizer\ExpressionSupportTrait`
- [FilterCompilerTrait](Clarity_Engine_Tokenizer_FilterCompilerTrait.md) `Clarity\Engine\Tokenizer\FilterCompilerTrait`
- [OperatorTestTrait](Clarity_Engine_Tokenizer_OperatorTestTrait.md) `Clarity\Engine\Tokenizer\OperatorTestTrait`
- [PhpConstructTrait](Clarity_Engine_Tokenizer_PhpConstructTrait.md) `Clarity\Engine\Tokenizer\PhpConstructTrait`
- [SegmentScannerTrait](Clarity_Engine_Tokenizer_SegmentScannerTrait.md) `Clarity\Engine\Tokenizer\SegmentScannerTrait`
- [VarChainTrait](Clarity_Engine_Tokenizer_VarChainTrait.md) `Clarity\Engine\Tokenizer\VarChainTrait`

### `Clarity\Localization`

- [ArrayTranslationLoader](Clarity_Localization_ArrayTranslationLoader.md) `Clarity\Localization\ArrayTranslationLoader`
- [CatalogNormalizationTrait](Clarity_Localization_CatalogNormalizationTrait.md) `Clarity\Localization\CatalogNormalizationTrait`
- [ChainTranslationLoader](Clarity_Localization_ChainTranslationLoader.md) `Clarity\Localization\ChainTranslationLoader`
- [FileTranslationLoader](Clarity_Localization_FileTranslationLoader.md) `Clarity\Localization\FileTranslationLoader`
- [IntlFormatModule](Clarity_Localization_IntlFormatModule.md) `Clarity\Localization\IntlFormatModule`
- [LocaleService](Clarity_Localization_LocaleService.md) `Clarity\Localization\LocaleService`
- [RedisCachingLoader](Clarity_Localization_RedisCachingLoader.md) `Clarity\Localization\RedisCachingLoader`
- [TranslationLoaderInterface](Clarity_Localization_TranslationLoaderInterface.md) `Clarity\Localization\TranslationLoaderInterface`
- [TranslationModule](Clarity_Localization_TranslationModule.md) `Clarity\Localization\TranslationModule`
- [YamlParser](Clarity_Localization_YamlParser.md) `Clarity\Localization\YamlParser`

### `Clarity\Template`

- [ArrayLoader](Clarity_Template_ArrayLoader.md) `Clarity\Template\ArrayLoader`
- [CompositeLoader](Clarity_Template_CompositeLoader.md) `Clarity\Template\CompositeLoader`
- [DomainRouterLoader](Clarity_Template_DomainRouterLoader.md) `Clarity\Template\DomainRouterLoader`
- [FileLoader](Clarity_Template_FileLoader.md) `Clarity\Template\FileLoader`
- [StringLoader](Clarity_Template_StringLoader.md) `Clarity\Template\StringLoader`
- [TemplateLoader](Clarity_Template_TemplateLoader.md) `Clarity\Template\TemplateLoader`
- [TemplateLocation](Clarity_Template_TemplateLocation.md) `Clarity\Template\TemplateLocation`
- [TemplateSource](Clarity_Template_TemplateSource.md) `Clarity\Template\TemplateSource`

