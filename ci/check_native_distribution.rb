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
  'x86_64-pc-windows-msvc' => 'ktav_cabi-windows-amd64.dll',
  'aarch64-pc-windows-msvc' => 'ktav_cabi-windows-arm64.dll'
}
abort 'native target or asset matrix changed' unless settings.length == expected.length && settings.to_h { |item| [item.fetch('target'), item.fetch('asset')] } == expected
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
