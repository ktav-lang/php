require 'yaml'

path = './.github/workflows/native-distribution.yml'
workflow = YAML.load_file(path)
triggers = workflow['on'] || workflow[true]
abort 'missing native workflow contract' unless triggers.fetch('workflow_call').fetch('inputs').fetch('source-ref').fetch('required')
job = workflow.fetch('jobs').fetch('build-native')
settings = job.fetch('strategy').fetch('matrix').fetch('settings')
expected = {
  'x86_64-unknown-linux-gnu' => 'libktav_cabi-linux-amd64.so',
  'aarch64-unknown-linux-gnu' => 'libktav_cabi-linux-arm64.so',
  'x86_64-apple-darwin' => 'libktav_cabi-darwin-amd64.dylib',
  'aarch64-apple-darwin' => 'libktav_cabi-darwin-arm64.dylib',
  'x86_64-pc-windows-gnullvm' => 'ktav_cabi-windows-amd64.dll',
  'aarch64-pc-windows-gnullvm' => 'ktav_cabi-windows-arm64.dll'
}
gnu_path = './.github/workflows/windows-gnu-cabi-candidate.yml'
gnu_call = workflow.fetch('jobs').fetch('windows-gnu')
abort 'wrong GNU producer caller' unless gnu_call.fetch('uses') == gnu_path
abort 'GNU source is not forwarded' unless gnu_call.fetch('with').fetch('source-ref') == '${{ inputs.source-ref }}'
gnu = YAML.load_file(gnu_path)
gnu_triggers = gnu['on'] || gnu[true]
abort 'missing GNU exact-source contract' unless gnu_triggers.fetch('workflow_call').fetch('inputs').fetch('source-ref').fetch('required')
abort 'duplicate GNU PR producer' if gnu_triggers.key?('pull_request')
gnu_jobs = gnu.fetch('jobs')
gnu_targets = gnu_jobs.fetch('cabi-gnullvm').fetch('strategy').fetch('matrix').fetch('include').map { |item| item.fetch('target') }
abort 'wrong GNU target matrix' unless gnu_targets.sort == %w[x86_64-pc-windows-gnullvm aarch64-pc-windows-gnullvm].sort
consumers = gnu_jobs.fetch('php-consumers')
abort 'missing GNU producer dependency' unless consumers.fetch('needs') == 'cabi-gnullvm'
actual = consumers.fetch('strategy').fetch('matrix').fetch('include').map { |item| [item.fetch('os'), item.fetch('arch'), item.fetch('target')] }
required = [['windows-latest', 'x64', 'x86_64-pc-windows-gnullvm'], ['windows-11-arm', 'arm64', 'aarch64-pc-windows-gnullvm']]
abort 'incomplete genuine PHP consumer matrix' unless actual.sort == required.sort
consumer_step = consumers.fetch('steps').find { |item| item['name'] == 'Execute unchanged original PHP suites on the genuine CPU' }
abort 'missing mandatory PHP consumer execution' unless consumer_step && !consumer_step.key?('if') && !consumer_step['continue-on-error'] && consumer_step.fetch('run').include?('check_php_gnu_consumer.py')
staging = gnu_jobs.fetch('stage-release')
abort 'missing complete GNU consumer gate' unless staging.fetch('needs').sort == %w[cabi-gnullvm php-consumers].sort
windows = staging.fetch('strategy').fetch('matrix').fetch('settings')
abort 'native target or asset matrix changed' unless settings.length == 4 && windows.length == 2 && (settings + windows).to_h { |item| [item.fetch('target'), item.fetch('asset')] } == expected
steps = staging.fetch('steps')
abort 'missing GNU staging command' unless steps.any? { |item| item['run'].to_s.include?('python -I scripts/stage_php_gnu_release.py') && !item.key?('if') && !item['continue-on-error'] }
outputs = steps.select { |item| item['uses'].to_s.start_with?('actions/upload-artifact@') }
abort 'wrong GNU distribution artifact role' unless outputs.length == 1 && outputs[0].fetch('with').fetch('name') == 'native-distribution-${{ matrix.settings.target }}' && outputs[0].fetch('with').fetch('path') == '${{ runner.temp }}/php-gnu-release/' && outputs[0].fetch('with').fetch('if-no-files-found') == 'error' && !outputs[0].key?('if') && !outputs[0]['continue-on-error']
stager = File.read('scripts/stage_php_gnu_release.py')
%w[ktav_cabi-windows-amd64.dll ktav_cabi-windows-arm64.dll .NOTICE.json .NOTICE.manifest.json check_embedded_notices.py source_ref target].each do |role|
  abort "missing GNU delivery role #{role}" unless stager.include?(role)
end
caller = YAML.load_file('.github/workflows/native-distribution-check.yml').fetch('jobs').fetch('native-distribution')
abort 'wrong native CI caller' unless caller.fetch('uses') == path
abort 'native CI source is not published PHP 0.8.1' unless caller.fetch('with').fetch('source-ref') == 'c979b98d6decfb3c2808c8177eecf5d34dbc8634'
release = YAML.load_file('.github/workflows/release.yml').fetch('jobs')
producer = release.fetch('build-native')
abort 'production native build differs from CI' unless producer.fetch('uses') == path
abort 'production source must follow caller commit' unless producer.fetch('with').fetch('source-ref') == '${{ github.sha }}'
abort 'missing native publication gate' unless Array(release.fetch('release').fetch('needs')).include?('build-native')
downloads = release.fetch('release').fetch('steps').select { |step| step['uses'].to_s.start_with?('actions/download-artifact@') }
abort 'unsafe artifact download' unless downloads.map { |step| step.fetch('with').slice('name', 'pattern') } == [{'pattern' => 'native-distribution-*'}]
steps = job.fetch('steps')
['Prepare locked native notice resource', 'Verify embedded resource and write distribution sidecars', 'Run PHP tests on matching target architecture'].each do |name|
  step = steps.find { |item| item['name'] == name }
  abort "missing mandatory native gate: #{name}" unless step && !step.key?('if') && !step['continue-on-error']
end
upload = steps.find { |step| step['uses'].to_s.start_with?('actions/upload-artifact@') && step.fetch('with').fetch('name').start_with?('native-distribution-') }
abort 'missing native notice sidecars' unless upload && upload.fetch('with').fetch('path').include?('.NOTICE.json') && upload.fetch('with').fetch('path').include?('.NOTICE.manifest.json')
puts 'PHP native source, notice, runtime and publication contracts verified'
