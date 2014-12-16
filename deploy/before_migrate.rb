template "#{release_path}/app/config/parameters.yml.erb" do
  source "#{release_path}/deploy/parameters.yml"
  owner "apache"
  group "apache"
  mode "0644"
  local true
end
