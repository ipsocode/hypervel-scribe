# Hypervel pages for each part of src/

Before changing a part of `src/`, read the sections of Hypervel 0.4's documentation listed for it
below, as page › heading. The pages are in `vendor/hypervel/components/src/docs/`; where the
hypervel-porting MCP server is set up, its `read_doc` reads a page, or one section of it.

| Part of src/ | Read |
|---|---|
| `ScribeServiceProvider.php` | packages.md › Default Package Configuration; packages.md › Merging Configuration Arrays; packages.md › Routes; packages.md › Language Files; packages.md › Views; packages.md › Publishing Views; packages.md › Commands; packages.md › "About" Artisan Command; providers.md › Service Providers and Long-Running Workers |
| `Matching/`, `GroupedEndpoints/`, `Tools/RoutePatternMatcher.php`: which routes are documented | routing.md › Named Routes; routing.md › Route Prefixes; routing.md › Subdomain Routing |
| `Extracting/Strategies/UrlParameters/`, `Extracting/Shared/UrlParamsNormalizer.php` | routing.md › Route Parameters; routing.md › Regular Expression Constraints; routing.md › Route Model Binding |
| `Extracting/FindsFormRequestForMethod.php`, `Extracting/*ValidationRules.php`, `Extracting/GeneratesRuleExamples.php`, `Extracting/NormalisesValidationParameters.php`, `Extracting/Strategies/GetFromFormRequestBase.php`, `Extracting/Shared/ValidationRulesFinders/` | validation.md › Form Request Validation; validation.md › Manually Creating Validators; validation.md › Available Validation Rules; validation.md › Validating Arrays; validation.md › Custom Validation Rules |
| `Extracting/Extractor.php`, `Extracting/ParamHelpers.php`: body and file parameters | requests.md › Retrieving Uploaded Files; validation.md › Validating Files |
| `Extracting/Strategies/Responses/ResponseCalls.php` | lifecycle.md › Handling Requests; coroutine-context.md › Request Context; coroutines.md › The `wait` Helper; lifecycle.md › Per-Worker vs. Per-Request; database.md › Database Transactions |
| `Extracting/DatabaseTransactionHelpers.php` | database.md › Database Transactions; database.md › Using Multiple Database Connections |
| `Extracting/InstantiatesExampleModels.php`, `Tools/Utils.php`: example models | eloquent-factories.md › Instantiating Models; eloquent-factories.md › Persisting Models; eloquent-factories.md › Factory Relationships |
| `Extracting/Shared/ApiResourceResponseTools.php`, `Extracting/Shared/TransformerResponseTools.php`, `Extracting/Strategies/Responses/`, `Attributes/ResponseFromApiResource.php`: API resource and transformer responses | eloquent-resources.md › Resource Collections; eloquent-resources.md › Data Wrapping; eloquent-resources.md › Pagination; eloquent-resources.md › Resource Responses; pagination.md › Manually Creating a Paginator; pagination.md › Converting Results to JSON |
| `Extracting/ApiDetails.php`, `Tools/Utils.php` (`trans()`): translated descriptions | localization.md › Retrieving Translation Strings; localization.md › Replacing Parameters in Translation Strings; localization.md › Overriding Package Language Files |
| `Writing/HtmlWriter.php`, `Writing/ExternalHtmlWriter.php`, `Tools/BladeMarkdownEngine.php` | views.md › Creating and Rendering Views; views.md › View Namespaces; blade.md › HTML Entity Encoding; blade.md › Rendering Inline Blade Templates |
| `Writing/Writer.php` | filesystem.md › The Local Driver; filesystem.md › Obtaining Disk Instances; filesystem.md › Storing Files; routing.md › Named Routes |
| `Commands/` | artisan.md › Writing Commands; artisan.md › Coroutine Execution; artisan.md › Programmatically Executing Commands; urls.md › Customizing URL Origins; coroutine-context.md › Retrieving Values |
| `Jobs/` | queues.md › Class Structure; queues.md › Preventing Job Overlaps; artisan.md › Programmatically Executing Commands |
| `Config/`, `Tools/DocumentationConfig.php`, `Tools/ConfigDiffer.php` | configuration.md › Accessing Configuration Values; packages.md › Default Package Configuration |
| `Scribe.php`, `Tools/Globals.php`, `Tools/RunState.php`: state kept for the life of a worker | lifecycle.md › Per-Worker vs. Per-Request; packages.md › Test State Cleanup |
| `Testing/` | packages.md › Test State Cleanup |

## Default References

- packages.md › Package Discovery
- packages.md › Service Providers
- packages.md › Test State Cleanup
- coroutines.md
